<?php

// Internal config
const OPENROUTER_BASE_URL   = 'https://openrouter.ai/api';
const PROMPT_SUCCESS_STRING = '✨';

// Load core components
require_once __DIR__ . '/.config.php';
require_once __DIR__ . '/functions-prompts.php';
require_once __DIR__ . '/functions-openrouter.php';

// === Config Validation ===
function validate_config(): void {
    $required = [
        'OPENROUTER_API_KEY',
        'SRC_DIR',
        'OUT_DIR',
        'MD_FILES',
    ];

    foreach ($required as $const) {
        if (!defined($const)) {
            die("Configuration error: Constant '$const' is not defined in .config.php\n");
        }
    }

    if (empty(OPENROUTER_API_KEY) || !str_starts_with(OPENROUTER_API_KEY, 'sk-or-')) {
        die("Configuration error: OPENROUTER_API_KEY is missing or invalid.\n");
    }

    if (!is_dir(SRC_DIR)) {
        die("Configuration error: SRC_DIR does not exist: " . SRC_DIR . "\n");
    }

    if (!is_dir(OUT_DIR) && !@mkdir(OUT_DIR, 0777, true)) {
        die("Configuration error: Could not create OUT_DIR: " . OUT_DIR . "\n");
    }

    if (!is_writable(OUT_DIR)) {
        die("Configuration error: OUT_DIR is not writable: " . OUT_DIR . "\n");
    }
}

validate_config();

// === CLI Color Helpers ===
const COLORS = [
    'reset'   => "\033[0m",
    'bold'    => "\033[1m",
    'dim'     => "\033[2m",
    'green'   => "\033[32m",
    'yellow'  => "\033[33m",
    'red'     => "\033[31m",
    'blue'    => "\033[34m",
    'cyan'    => "\033[36m",
    'magenta' => "\033[35m",
    'gray'    => "\033[90m",
    'white'   => "\033[97m",
];

function color(string $text, string $color = 'reset'): string {
    $code = COLORS[$color] ?? COLORS['reset'];
    return $code . $text . COLORS['reset'];
}

// Semantic helpers
function success(string $text): string { return color($text, 'green'); }
function error(string $text): string   { return color($text, 'red'); }
function warn(string $text): string    { return color($text, 'yellow'); }
function info(string $text): string    { return color($text, 'cyan'); }
function dim(string $text): string     { return color($text, 'dim'); }
