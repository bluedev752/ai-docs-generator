<?php

function get_relevant_files_modification_time(string $mdFilename): int {
    $latest = 0;
    foreach (MD_FILES[$mdFilename]['relevant_files'] as $file) {
        $fullPath = rtrim(SRC_DIR, '\\/') . DIRECTORY_SEPARATOR . ltrim($file, '\\/');
        $mtime = filemtime($fullPath);
        if ($mtime > $latest) {
            $latest = $mtime;
        }
    }
    return $latest;
}

function calculate_md_hash(string $mdFilename): string {
    $data = json_encode([
        MD_FILES[$mdFilename], // 1: array The md file config array
        get_relevant_files_modification_time($mdFilename), // 2: int The relevant files modification time
        get_prompts_version(), // 3: string The prompts version
    ]);
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
    $currentHash = calculate_md_hash($mdFilename);
    if (!$savedHash) {
        return 'New';
    }
    if ($savedHash !== $currentHash) {
        return 'Changed';
    }
    return 'Unchanged';
}
