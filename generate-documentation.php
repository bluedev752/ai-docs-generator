#!/usr/bin/env php
<?php

require __DIR__ . '/load.php';

echo color("======= AI Docs Generator =======", 'bold') . "\n\n";

function select_model(): string {
    echo info("Fetching available free models...") . "\n";
    $freeModels = openrouter_free_models();

    if (isset($freeModels['error'])) {
        die("Error fetching free models list: {$freeModels['error']}\n");
    }

    if (empty($freeModels)) {
        die("Error: No free models available from OpenRouter.\n");
    }

    echo "\n" . color("Available models:", 'bold') . "\n";
    foreach ($freeModels as $i => $m) {
        printf("  %d. %s\n", $i + 1, $m);
    }

    $model = null;
    while ($model === null) {
        echo "\nSelect model number: ";
        $input = trim(fgets(STDIN));
        $idx = (int)$input - 1;

        if (isset($freeModels[$idx])) {
            $model = $freeModels[$idx];
        } else {
            echo "Invalid selection. Please try again.\n";
        }
    }

    echo success("\nSelected model: $model") . "\n";
    return $model;
}

function handle_step_failure(string $message, string &$model): bool {
    $lower = strtolower($message);

    // Authentication / API key failure → fatal, no retry
    // if (str_contains($lower, 'authentication') ||
    //     str_contains($lower, 'api key') ||
    //     str_contains($lower, 'unauthorized') ||
    //     str_contains($lower, 'invalid key')) {
    //     die("\nFatal: Authentication failed. Check your OPENROUTER_API_KEY and try again.\n");
    // }

    // Rate limit with explicit wait time
    // if (preg_match('/rate.?limit|too many requests/i', $message)) {
    //     if (preg_match('/(\d+)\s*seconds?/i', $message, $matches)) {
    //         $wait = (int)$matches[1];
    //         echo warn("Rate limited by OpenRouter. Waiting {$wait} seconds...") . "\n";
    //         sleep($wait);
    //         return true; // proceed to retry prompt after waiting
    //     }
    // }

    // Unhandled error → allow normal retry prompt
    return true;
}

function generate_documentation(string $mdFilename, string &$model): void {
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

                if ($response !== null && $response !== '') {
                    $results[$function] = $response;
                    $duration = round(microtime(true) - $start, 1);
                    echo success("✓ Completed successfully.") . dim(" ({$duration}s)") . "\n";
                    $success = true;
                } else {
                    ai_rollback_last_turn();
                    echo error("✗ Returned empty response.") . "\n";
                }
            } catch (Throwable $e) {
                ai_rollback_last_turn();
                $msg = $e->getMessage();
                echo error("✗ Error: $msg") . "\n";
                handle_step_failure($msg, $model);
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
    printf("  %d. %s (%s)\n", $i + 1, MD_FILES[$key]['title'], $key . '.md');
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
