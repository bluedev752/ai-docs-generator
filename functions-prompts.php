<?php

function ai_read_relevant_files(string $mdFilename, string $model): string {
    $common_relevant_files = COMMON_RELEVANT_FILES;
    $specific_relevant_files = MD_FILES[$mdFilename]['relevant_files'] ?? [];
    $prompt = str_replace(
        '{{relevant_files}}',
        build_prompt_include_files_content(
            array_merge($common_relevant_files ?: [], $specific_relevant_files ?: [])
        ),
        get_prompt('read_relevant_files', $mdFilename)
    );
    $response = ai_run_prompt($prompt, 'read_relevant_files', $model);
    return filter_prompt_response_by_success_string($response);
}

function ai_read_documentation_rules(string $mdFilename, string $model): string {
    $prompt = get_prompt('read_documentation_rules', $mdFilename);
    $response = ai_run_prompt($prompt, 'read_documentation_rules', $model);
    return filter_prompt_response_by_success_string($response);
}

function ai_prepare_documentation_task(string $mdFilename, string $model): string {
    $prompt = get_prompt('prepare_documentation_task', $mdFilename);
    $response = ai_run_prompt($prompt, 'prepare_documentation_task', $model);
    return filter_prompt_response_by_success_string($response);
}

function ai_start_documentation_writing(string $mdFilename, string $model): string {
    $prompt = get_prompt('start_documentation_writing', $mdFilename);
    $response = ai_run_prompt($prompt, 'start_documentation_writing', $model);
    return filter_prompt_response_by_lines($response, $mdFilename);
}

function ai_review_created_documentation(string $mdFilename, string $model): string {
    $prompt = get_prompt('review_created_documentation', $mdFilename);
    $response = ai_run_prompt($prompt, 'review_created_documentation', $model);
    return filter_prompt_response_by_lines($response, $mdFilename);
}

/** Helpers */

function ai_start_conversation(): void {
    $GLOBALS['ai_messages'] = [];
}

function ai_rollback_last_turn(): void {
    $messages = &$GLOBALS['ai_messages'];
    if (!empty($messages) && end($messages)['role'] === 'assistant') {
        array_pop($messages);
    }
    if (!empty($messages) && end($messages)['role'] === 'user') {
        array_pop($messages);
    }
}

function ai_run_prompt(string $prompt, string $prompt_key, string $model): ?string {
    $messages = &$GLOBALS['ai_messages'];
    $messages[] = ['role' => 'user', 'content' => $prompt];
    $response = openrouter_chat($messages, $model);
    $messages[] = ['role' => 'assistant', 'content' => $response];
    return $response;
}

/** Applies generic replacements */
function get_prompt(string $prompt_key, string $mdFilename): string {
    $path = __DIR__ . "/prompts/{$prompt_key}.txt";
    if (!file_exists($path)) {
        throw new RuntimeException("Prompt not found: $prompt_key");
    }
    $prompt = trim(file_get_contents($path));
    if (!is_string($prompt) || $prompt === '') {
        throw new RuntimeException("Prompt file could not be read: $path");
    }
    // Replace success key
    $prompt = str_replace('{{success_string}}', PROMPT_SUCCESS_STRING, $prompt);
    // Replace md filename
    $prompt = str_replace('{{md_filename}}', $mdFilename, $prompt);
    // Replace md title
    $prompt = str_replace('{{md_title}}', MD_FILES[$mdFilename]['title'], $prompt);
    // Replace exclude_concepts if present
    $prompt = str_replace('{{exclude_concepts}}', MD_FILES[$mdFilename]['exclude_concepts'] ?? '(none specified)', $prompt);
    return $prompt;
}

function build_prompt_include_files_content(array $files): string {
    $content = '';
    foreach ($files as $file) {
        $base = rtrim(SRC_DIR, '\\/');
        $rel  = ltrim($file, '\\/');
        $fullPath = $base . DIRECTORY_SEPARATOR . $rel;
        $fileContent = file_get_contents($fullPath);
        $content .= "=== FILE: $file ===\n$fileContent\n\n";
    }
    return $content;
}

/** Filter response by configured success string */
function filter_prompt_response_by_success_string(string $response): string {
    if (!str_contains($response, PROMPT_SUCCESS_STRING)) {
        throw new RuntimeException("Empty or invalid response (missing success string). Raw response: $response");
    }
    return $response;
}

/** Filter response by configured min lines count */
function filter_prompt_response_by_lines(string $response, string $mdFilename): string {
    $min = intval(MD_FILES[$mdFilename]['min_lines'] ?? 0);
    if ($min < 1) {
        return $response;
    }
    $lines = substr_count($response, "\n") + 1;
    if ($lines < $min) {
        throw new RuntimeException("Response too short for $mdFilename (got $lines lines, need $min). Raw: $response");
    }
    return $response;
}

function get_prompt_version(): string {
    $files = glob(__DIR__ . '/prompts/*.txt');
    $latest = 0;
    foreach ($files ?: [] as $f) {
        $t = @filemtime($f);
        if ($t > $latest) $latest = $t;
    }
    return $latest ? date('Y-m-d H:i:s', $latest) : 'unknown';
}
