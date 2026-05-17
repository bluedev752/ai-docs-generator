#!/usr/bin/env php
<?php

require __DIR__ . '/load.php';

echo color("=== AI Docs Generator ===", 'bold') . "\n\n";

function select_model(): string {
    echo info("Fetching available free models...") . "\n";
    $freeModels = openrouter_free_models();

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

    echo success("Selected model: $model") . "\n";
    return $model;
}

$model = select_model();

// Step 2: Select MD file
$mdKeys = array_keys(MD_FILES);
if (empty($mdKeys)) {
    die("Error: No MD_FILES defined in configuration.\n");
}

echo "\n" . color("Available documentation targets:", 'bold') . "\n";
foreach ($mdKeys as $i => $key) {
    printf("  %d. %s (%s)\n", $i + 1, $key, MD_FILES[$key]['title']);
}

$mdFilename = null;
while ($mdFilename === null) {
    echo "\nSelect target number: ";
    $input = trim(fgets(STDIN));
    $idx = (int)$input - 1;

    if (isset($mdKeys[$idx])) {
        $mdFilename = $mdKeys[$idx];
    } else {
        echo "Invalid selection. Please try again.\n";
    }
}

echo success("Selected: $mdFilename") . "\n";

// Step 3: Run documentation pipeline with robust retry
ai_start_conversation();

$steps = [
    'ai_read_relevant_files'        => 'Reading relevant source files',
    'ai_read_documentation_rules'   => 'Reading documentation rules',
    'ai_prepare_documentation_task' => 'Preparing documentation task',
    'ai_start_documentation_writing' => 'Writing initial documentation',
    'ai_review_created_documentation' => 'Reviewing and finalizing',
];

$results = [];
$maxRetries = 3;

foreach ($steps as $function => $label) {
    $attempt = 0;
    $success = false;

    while (!$success && $attempt < $maxRetries) {
        $attempt++;
        echo "\n" . info("$label") . dim(" (attempt $attempt/$maxRetries)") . "\n";

        try {
            $response = $function($mdFilename, $model);

            if ($response !== null && $response !== '') {
                $results[$function] = $response;
                echo success("✓ Completed successfully.") . "\n";
                $success = true;
            } else {
                ai_rollback_last_turn();
                echo error("✗ Returned empty response.") . "\n";
            }
        } catch (Throwable $e) {
            ai_rollback_last_turn();
            echo error("✗ Error: " . $e->getMessage()) . "\n";
        }

        if (!$success && $attempt < $maxRetries) {
            echo "Options: [r]etry same, [m]odel change + retry, [a]bort: ";
            $answer = strtolower(trim(fgets(STDIN)));
            if ($answer === 'm') {
                $model = select_model();
                $attempt = 0;
            } elseif ($answer !== 'r') {
                break;
            }
        }
    }

    if (!$success) {
        die("\nFatal: Failed to complete \"$label\" after $maxRetries attempts.\n");
    }
}

// Step 4: Save final documentation
$finalContent = $results['ai_review_created_documentation'] ?? $results['ai_start_documentation_writing'] ?? null;

if ($finalContent === null || strlen($finalContent) < 100) {
    die("Error: Final documentation content is too short or missing.\n");
}

$outputFile = OUT_DIR . DIRECTORY_SEPARATOR . $mdFilename . '.md';

if (file_put_contents($outputFile, $finalContent) === false) {
    die("Error: Failed to write output file: $outputFile\n");
}

echo "\n" . success("✓ Documentation successfully generated!") . "\n";
echo dim("Saved to: $outputFile") . "\n";
echo "Total characters: " . strlen($finalContent) . "\n";
