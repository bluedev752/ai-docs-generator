#!/usr/bin/env php
<?php

require __DIR__ . '/load.php';

echo color("======= AI Docs Generator =======", 'bold') . "\n\n";

function select_model(): string {
    $useFreeOnly = defined('USE_FREE_MODELS_ONLY') && USE_FREE_MODELS_ONLY;

    if ($useFreeOnly) {
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

function generate_documentation(string $mdFilename, string &$model): void {

    $relevantFilesHash = calculate_relevant_files_hash($mdFilename); // Will be saved in history on success

    $startTime = microtime(true);
    ai_start_conversation();

    $results = [];
    foreach (PROMPT_STEPS as $function => $label) {
        $attempt = 0;
        $success = false;
        while (!$success) {

            $attempt++;
            echo "\n" . info("$label") . dim(" (attempt $attempt)") . "\n";
            $start = microtime(true);

            try {
                $response = $function($mdFilename, $model);
                $results[$function] = $response;
                $duration = round(microtime(true) - $start, 1);
                echo success("✓ Completed successfully.") . dim(" ({$duration}s)") . "\n";
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
                    $model = select_model();
                    $attempt = 0;
                } elseif ($answer !== 'r') {
                    die("\nAborted at \"$label\".\n");
                }
            }

        }
    }

    // Save final documentation
    $finalContent = $results['ai_review_created_documentation'] ?? $results['ai_start_documentation_writing'] ?? null;

    if ($finalContent === null || strlen($finalContent) < 100) {
        die("Error: Final documentation content is too short or missing.\n");
    }

    $finalContent .= "\n\n---\n*Generated with `$model` on " . date('Y-m-d') . " (prompts v" . get_prompt_version() . ")*\n";

    $outputFile = OUT_DIR . DIRECTORY_SEPARATOR . $mdFilename . '.md';

    if (file_put_contents($outputFile, $finalContent) === false) {
        die("Error: Failed to write output file: $outputFile\n");
    }

    // Save sources hash in history
    upsert_history_entry($mdFilename, $relevantFilesHash);

    echo "\n" . success("✓ Documentation successfully generated!") . "\n";
    echo dim("Saved to: $outputFile") . "\n";
    echo "Total characters: " . strlen($finalContent) . "\n";

    $totalDuration = round(microtime(true) - $startTime, 1);
    echo dim("Total time: {$totalDuration}s") . "\n";
}

$model = select_model();

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
    generate_documentation($mdFilename, $model);
}
