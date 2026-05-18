<?php

function get_relevant_files_modification_time(string $mdFilename): int {
    $latest = 0;
    foreach (MD_FILES[$mdFilename]['relevant_files'] ?? [] as $file) {
        $fullPath = rtrim(SRC_DIR, '\\/') . DIRECTORY_SEPARATOR . ltrim($file, '\\/');
        $mtime = is_file($fullPath) ? filemtime($fullPath) : 0;
        if ($mtime > $latest) {
            $latest = $mtime;
        }
    }
    return $latest;
}

function calculate_relevant_files_hash(string $mdFilename): string {
    $config = MD_FILES[$mdFilename] ?? [];
    $timestamp = get_relevant_files_modification_time($mdFilename);
    $data = json_encode([$config, $timestamp]);
    return sprintf('%08x', crc32($data));
}

function upsert_history_entry(string $mdFilename, string $mdSourcesHash): bool {
    $history = file_exists(HISTORY_FILE) ? (json_decode(file_get_contents(HISTORY_FILE), true) ?: []) : [];
    $history[$mdFilename] = $mdSourcesHash;
    return boolval(file_put_contents(HISTORY_FILE, json_encode($history, JSON_PRETTY_PRINT)));
}

function get_documentation_status(string $mdFilename): string {
    if (!file_exists(HISTORY_FILE)) {
        return 'New';
    }
    $history = json_decode(file_get_contents(HISTORY_FILE), true) ?: [];
    $savedHash = $history[$mdFilename] ?? null;
    $currentHash = calculate_relevant_files_hash($mdFilename);
    if (!$savedHash) {
        return 'New';
    }
    if ($savedHash !== $currentHash) {
        return 'Changed';
    }
    return 'Unchanged';
}
