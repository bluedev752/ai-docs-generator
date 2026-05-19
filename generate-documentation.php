#!/usr/bin/env php
<?php

require __DIR__ . '/load.php';

echo color("======= AI Docs Generator =======", 'bold') . "\n\n";

function select_model(): string {
    if (USE_FREE_MODELS_ONLY) {
        echo info("Fetching available free models...") . "\n";
        $models = openrouter_free_models();
    } else {
        echo info("Fetching all available models...") . "\n";
        $models = openrouter_all_models();
    }
    if (isset($models['error'])) {
        die("Error fetching models: {$models['error']}\n");
    }
    if (empty($models)) {
        die("Error: No models available.\n");
    }
    echo "\n" . color("Available models:", 'bold') . "\n";
    foreach ($models as $i => $m) {
        printf("  %d. %s\n", $i + 1, $m);
    }
    $model = null;
    while ($model === null) {
        echo "\nSelect model number: ";
        $input = trim(fgets(STDIN));
        $idx = (int)$input - 1;

        if (isset($models[$idx])) {
            $model = $models[$idx];
        } else {
            echo "Invalid selection. Please try again.\n";
        }
    }
    $GLOBALS['MODEL'] = $model;
    echo success("\nSelected model: $model") . "\n";
    return $model;
}

function handle_step_failure(Throwable $e): bool {
    // Rate limit (structured or string)
    if ($e instanceof OpenRouterException && $e->httpCode === 429) {
        $reset = intval($e->errorData['error']['metadata']['headers']['X-RateLimit-Reset'] ?? 0);
        $wait = $reset ? max(0, intval($reset / 1000) - time() + 2) : 0;
        if ($wait > 0) {
            echo warn("Rate limited. Waiting {$wait}s...") . "\n";
            sleep($wait);
        } else {
            echo warn("Rate limited. Could not determine reset timestamp. Retry manually.") . "\n";
        }
        return true;
    }
    return true;
}

function generate_documentation(string $mdFilename): void {

    $mdHash = calculate_md_hash($mdFilename); // Will be saved in history on success

    $file_start_time = time();
    ai_start_conversation();

    $result = null;
    foreach (PROMPTS as $promptFilename => $promptConfig) {

        $label = ucwords(str_replace('_', ' ', $promptFilename));
        $attempt = 0;
        $success = false;
        while (!$success) {

            $attempt++;
            echo "\n" . info("$label") . dim(" (attempt $attempt)") . "\n";
            $attempt_start = time();

            try {
                $result = ai_run_prompt($promptFilename, $mdFilename);
                $attempt_duration = time() - $attempt_start;
                echo success("✓ Completed successfully.") . dim(" ({$attempt_duration}s)") . "\n";
                $success = true;
            } catch (Throwable $e) {
                ai_rollback_last_turn();
                echo error("✗ Error: " . $e->getMessage()) . "\n";
                handle_step_failure($e);
            }

            if (!$success) {
                echo "Options: [r]etry same, [m]odel change + retry, [a]bort: ";
                $answer = strtolower(trim(fgets(STDIN)));
                if ($answer === 'm') {
                    select_model();
                    $attempt = 0;
                } elseif ($answer !== 'r') {
                    die("\nAborted at \"$label\".\n");
                }
            }

        }
    }

    // Save final documentation

    if (!is_string($result) || strlen($result) < 128) {
        die("Error: Final documentation content is too short or missing.\n");
    }

    $result .= "\n\n---\n*Generated with `{$GLOBALS['MODEL']}` on " . date('Y-m-d') . " (prompts v" . get_prompts_version() . ")*\n";

    $outputFile = OUT_DIR . DIRECTORY_SEPARATOR . $mdFilename . '.md';

    if (file_put_contents($outputFile, $result) === false) {
        die("Error: Failed to write output file: $outputFile\n");
    }

    // Save sources hash in history
    upsert_history_entry($mdFilename, $mdHash);

    echo "\n" . success("✓ Documentation successfully generated!") . "\n";
    echo dim("Saved to: $outputFile") . "\n";
    echo "File characters: " . strlen($result) . "\n";

    $file_duration = time() - $file_start_time;
    echo dim("Generation took $file_duration seconds.") . "\n";
}

select_model();

// Step 2: Select MD targets (comma-separated numbers allowed)
$mdKeys = array_keys(MD_FILES);
if (empty($mdKeys)) {
    die("Error: No MD_FILES defined in configuration.\n");
}

echo "\n" . color("Available documentation targets:", 'bold') . "\n";
foreach ($mdKeys as $i => $key) {
    $status = get_documentation_status($key);
    $statusLabel = match($status) {
        'Unchanged' => dim("[$status]"),
        'New' => success("[$status]"),
        'Changed' => warn("[$status]"),
        default => "[$status]"
    };
    printf("  %d. %s %s %s\n", $i + 1, MD_FILES[$key]['title'], info("($key.md)"), $statusLabel);
}

$selectedFiles = [];
while (empty($selectedFiles)) {
    echo "\nSelect doc number(s) [comma-separated]: ";
    $input = trim(fgets(STDIN));
    $parts = preg_split('/[,\s]+/', $input);
    foreach ($parts as $part) {
        $idx = (int)$part - 1;
        if (isset($mdKeys[$idx])) {
            $selectedFiles[] = $mdKeys[$idx];
        }
    }
    if (empty($selectedFiles)) {
        echo "Invalid selection. Please try again.\n";
    }
}

$selectedFiles = array_unique($selectedFiles);
echo success("\nSelected: " . implode(', ', array_map(fn($f) => "$f.md", $selectedFiles))) . "\n";

// Run generation for each selected file
foreach ($selectedFiles as $mdFilename) {
    echo "\n" . color("===== Generating: $mdFilename.md =====", 'bold') . "\n";
    generate_documentation($mdFilename);
}
