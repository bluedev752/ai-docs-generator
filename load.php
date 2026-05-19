<?php

// Internal constants
const PROMPTS = [
    'read_documentation_rules' => [
        'response_filter' => 'success_string',
    ],
    'read_relevant_files' => [
        'response_filter' => 'success_string',
    ],
    'prepare_documentation_task' => [
        'response_filter' => 'success_string',
    ],
    'start_documentation_writing' => [
        'response_filter' => 'minimum_lines',
    ],
    'review_created_documentation' => [
        'response_filter' => 'minimum_lines',
    ],
];
const PROMPT_SUCCESS_STRING = '✨';
const OPENROUTER_BASE_URL = 'https://openrouter.ai/api';
const HISTORY_FILE = __DIR__ . '/.history.json';
const COLORS = [
    'reset' => "\033[0m",    'bold'    => "\033[1m",    'dim'  => "\033[2m",
    'green' => "\033[32m",   'yellow'  => "\033[33m",   'red'  => "\033[31m",   'blue'  => "\033[34m",
    'cyan'  => "\033[36m",   'magenta' => "\033[35m",   'gray' => "\033[90m",   'white' => "\033[97m",
];

// Internal globals
$GLOBALS['MODEL'] = null;
$GLOBALS['AI_MESSAGES'] = [];

// Load core components
require_once __DIR__ . '/functions-prompts.php';
require_once __DIR__ . '/functions-openrouter.php';
require_once __DIR__ . '/functions-history.php';

// Determine config file path
$configFile = __DIR__ . '/.config.php';
if (isset($argv)) {
    foreach ($argv as $i => $arg) {
        if (($arg === '-c' || $arg === '--config') && isset($argv[$i + 1])) {
            $p = $argv[$i + 1];
            $configFile = (str_starts_with($p, '/') || (strlen($p) > 1 && $p[1] === ':'))
                ? $p
                : getcwd() . DIRECTORY_SEPARATOR . ltrim($p, '\\/');
            break;
        }
    }
}
if (!is_file($configFile)) {
    die("Could not find configuration file at path: $configFile\n");
}
$configFile = realpath($configFile);
echo dim("Config file: $configFile\n");

// Load & validate config file
require_once $configFile;
validate_config();

////////////////////////////////////////////////////////////////////////

function validate_config(): void {
    $required = [
        'OPENROUTER_API_KEY' => 'string',
        'SRC_DIR' => 'string',
        'OUT_DIR' => 'string',
        'MD_FILES' => 'array',
    ];
    foreach ($required as $const => $data_type) {
        if (!defined($const)) {
            die("Configuration error: Constant '$const' is not defined as $data_type in .config.php\n");
        }
    }

    // Define all optional constants
    if (!defined('USE_FREE_MODELS_ONLY')) {
        define('USE_FREE_MODELS_ONLY', null);
    }
    if (!defined('COMMON_RELEVANT_FILES')) {
        define('COMMON_RELEVANT_FILES', []);
    }
    if (!defined('SOCKS5_PROXY')) {
        define('SOCKS5_PROXY', '');
    }
    if (!defined('REQUEST_TIMEOUT')) {
        define('REQUEST_TIMEOUT', 300);
    }

    if (!is_string(OPENROUTER_API_KEY) || !str_starts_with(OPENROUTER_API_KEY, 'sk-or-')) {
        die("Configuration error: OPENROUTER_API_KEY is missing or invalid.\n");
    }

    if (!is_string(SRC_DIR) || !is_dir(SRC_DIR)) {
        die("Configuration error: SRC_DIR does not exist: " . SRC_DIR . "\n");
    }

    if (!is_string(OUT_DIR) || !is_dir(OUT_DIR) && !@mkdir(OUT_DIR, 0777, true)) {
        die("Configuration error: Could not create OUT_DIR: " . OUT_DIR . "\n");
    }

    if (!is_writable(OUT_DIR)) {
        die("Configuration error: OUT_DIR is not writable: " . OUT_DIR . "\n");
    }

    // HISTORY_FILE
    $historyDir = dirname(HISTORY_FILE);
    if (!is_dir($historyDir) && !@mkdir($historyDir, 0777, true)) {
        die("Configuration error: Could not create directory for HISTORY_FILE: $historyDir\n");
    }
    if (!is_writable($historyDir)) {
        die("Configuration error: HISTORY_FILE directory is not writable: $historyDir\n");
    }

    if (!is_array(COMMON_RELEVANT_FILES)) {
        die("Configuration error: COMMON_RELEVANT_FILES must be an array.\n");
    }

    // Validate COMMON_RELEVANT_FILES paths
    foreach (COMMON_RELEVANT_FILES as $file) {
        $full = SRC_DIR . DIRECTORY_SEPARATOR . ltrim($file, '\\/');
        if (!file_exists($full)) {
            die("Configuration error: COMMON_RELEVANT_FILES file does not exist: $file\n");
        }
    }

    if (!is_array(MD_FILES) || empty(MD_FILES)) {
        die("Configuration error: MD_FILES must be a non-empty array.\n");
    }

    foreach (MD_FILES as $filename => $config) {
        if (!is_array($config)) {
            die("Configuration error: MD_FILES['$filename'] must be an array.\n");
        }
        if (empty($config['title']) || !is_string($config['title'])) {
            die("Configuration error: MD_FILES['$filename'] is missing a valid 'title'.\n");
        }
        if (empty($config['relevant_files']) || !is_array($config['relevant_files'])) {
            die("Configuration error: MD_FILES['$filename'] must have 'relevant_files' as an array.\n");
        }

        // Validate relevant_files paths
        foreach ($config['relevant_files'] as $file) {
            $full = SRC_DIR . DIRECTORY_SEPARATOR . ltrim($file, '\\/');
            if (!file_exists($full)) {
                die("Configuration error: MD_FILES['$filename']['relevant_files'] file does not exist: $file\n");
            }
        }
        if (isset($config['min_lines']) && (!is_int($config['min_lines']) || $config['min_lines'] < 0)) {
            die("Configuration error: MD_FILES['$filename']['min_lines'] must be a non-negative integer.\n");
        }
        if (isset($config['exclude_concepts']) && !is_string($config['exclude_concepts'])) {
            die("Configuration error: MD_FILES['$filename']['exclude_concepts'] must be a string.\n");
        }
        if (isset($config['high_priority_rules']) && !is_array($config['high_priority_rules'])) {
            die("Configuration error: MD_FILES['$filename']['high_priority_rules'] must be array.\n");
        }
        if (isset($config['high_priority_rules'])) {
            foreach ($config['high_priority_rules'] as $rule_idx => $rule) {
                if (!is_string($rule)) {
                    die("Configuration error: MD_FILES['$filename']['high_priority_rules'][$rule_idx] must be a string.\n");
                }
            }
        }
    }
    // SOCKS5_PROXY validation (optional)
    if (!is_string(SOCKS5_PROXY) || SOCKS5_PROXY !== '') {
        if (!preg_match('/^[\d\.:]+$/', SOCKS5_PROXY)) {
            die("Configuration error: Optional SOCKS5_PROXY must be in format 'ip:port' (e.g. 127.0.0.1:9050).\n");
        }
    }
}

// CLI Color Helper
function color(string $text, string $color = 'reset'): string {
    $code = COLORS[$color] ?? COLORS['reset'];
    return $code . $text . COLORS['reset'];
}

// Semantic helpers
function success(string $text): string { return color($text, 'green'); }
function error(string $text): string { return color($text, 'red'); }
function warn(string $text): string { return color($text, 'yellow'); }
function info(string $text): string { return color($text, 'cyan'); }
function dim(string $text): string { return color($text, 'dim'); }
