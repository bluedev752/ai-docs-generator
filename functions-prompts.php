<?php

/** Helpers */

function ai_start_conversation(): void {
    $GLOBALS['AI_MESSAGES'] = [];
}

function ai_rollback_last_turn(): void {
    if (!empty($GLOBALS['AI_MESSAGES']) && end($GLOBALS['AI_MESSAGES'])['role'] === 'assistant') {
        array_pop($GLOBALS['AI_MESSAGES']);
    }
    if (!empty($GLOBALS['AI_MESSAGES']) && end($GLOBALS['AI_MESSAGES'])['role'] === 'user') {
        array_pop($GLOBALS['AI_MESSAGES']);
    }
}

function ai_run_prompt(string $promptFilename, string $mdFilename): ?string {
    $prompt = get_prompt($promptFilename, $mdFilename); // Get prompt text
    $GLOBALS['AI_MESSAGES'][] = ['role' => 'user', 'content' => $prompt]; // Add to history
    $response = openrouter_chat(); // Fetch response
    $GLOBALS['AI_MESSAGES'][] = ['role' => 'assistant', 'content' => $response]; // Add to history
    // Filter response per configuration
    switch (PROMPTS[$promptFilename]['response_filter'] ?? null) {
        case 'success_string':
            return filter_prompt_response_by_success_string($response);
        case 'minimum_lines':
            return filter_prompt_response_by_lines($response, $mdFilename);
        default:
            error("Unhandled prompt response filter configuration.\n");
            return null;
    }
}

/** Applies generic replacements */
function get_prompt(string $promptFilename, string $mdFilename): string {
    $path = __DIR__ . "/prompts/{$promptFilename}.txt";
    if (!file_exists($path)) {
        throw new RuntimeException("Prompt not found: $promptFilename");
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
    // Replace excluded concepts if present
    $prompt = str_replace('{{exclude_concepts}}', MD_FILES[$mdFilename]['exclude_concepts'] ?? '(none specified)', $prompt);
    // Replace high priority rules if present
    $high_priority_rules = MD_FILES[$mdFilename]['high_priority_rules'] ?? [];
    $prompt = str_replace('{{high_priority_rules}}', implode("\n", $high_priority_rules) ?: '(none specified)', $prompt);
    // Replace relevant files
    if (str_contains($prompt, '{{relevant_file_list}}') || str_contains($prompt, '{{relevant_files}}')) {
        $all_relevant_files = array_merge(COMMON_RELEVANT_FILES, MD_FILES[$mdFilename]['relevant_files']);
        // Replace relevant file list
        $prompt = str_replace('{{relevant_file_list}}', implode("\n", $all_relevant_files), $prompt);
        // Replace relevant files content
        if (str_contains($prompt, '{{relevant_files}}')) {
            $prompt = str_replace('{{relevant_files}}', build_prompt_include_files_content($all_relevant_files), $prompt);
        }
    }
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

function get_prompts_version(): string {
    $files = glob(__DIR__ . '/prompts/*.txt');
    $latest = 0;
    foreach ($files ?: [] as $f) {
        $t = @filemtime($f);
        if ($t > $latest) $latest = $t;
    }
    return $latest ? date('Y-m-d H:i:s', $latest) : 'unknown';
}
