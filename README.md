# AI Docs Generator

Turn your source code into clean, thorough documentation — powered by AI.

A PHP CLI tool that reads your codebase, understands your architecture, and generates markdown documentation that actually makes sense. No fluff. No guesswork. Just precise, complete docs your team (and your future self) will love.

## What It Does

- **Reads your source files** — deeply analyzes PHP code, configurations, and prompts to understand what you're building.
- **Generates markdown** — produces complete documentation with sections for overview, behavior, configuration, edge cases, and more.
- **Enforces quality** — follows a strict style guide that eliminates redundancy, covers every concept, and documents every flow.
- **Supports AI models** — connects to OpenRouter so you can pick from free or paid models. Free-only mode available.
- **Multi-step pipeline** — runs read → prepare → write → review steps with automatic retries and error recovery.
- **Config-driven targets** — define which files to document, which sources are relevant, and minimum line requirements per output.
- **Change detection** — tracks source file modification times and prompt versions; only regenerates when something actually changed.
- **History tracking** — remembers what was generated and when, so you can see what's new or updated.

## Features at a Glance

- **Free model support** — filter available models to only those with zero prompt and completion cost.
- **Custom prompts** — modular prompt files (`prompts/*.txt`) let you control exactly how the AI approaches each step.
- **Line validation** — enforce minimum output length per documentation target.
- **Exclusion rules** — specify concepts to skip so the AI stays focused.
- **High-priority rules** — override default behavior with per-target priorities.
- **Rate limit handling** — automatic retry with backoff when OpenRouter returns 429, reading the reset timestamp from response headers.
- **SOCKS5 proxy support** — route traffic through a proxy when needed.
- **CLI color output** — clear visual feedback for every step, attempt, and result.
- **Graceful failure recovery** — on error you can retry, switch models, or abort — all from the terminal.
- **Prompt version tracking** — footer includes which prompt templates were used.

## Installation

```bash
git clone https://github.com/bluedev752/ai-docs-generator
cd ai-docs-generator
```

## Configuration

Copy [.config.php.example](.config.php.example) to `.config.php` and set your values:

**Required constants**:
- `OPENROUTER_API_KEY` — your OpenRouter API key (must start with `sk-or-`)
- `SRC_DIR` — directory containing your source files
- `OUT_DIR` — directory where generated docs are saved
- `MD_FILES` — array of documentation targets with `title`, `relevant_files`, and optional `min_lines`

**Optional constants**:
- `USE_FREE_MODELS_ONLY` — set to `true` to only show free models during selection
- `COMMON_RELEVANT_FILES` — files shared across all documentation targets
- `SOCKS5_PROXY` — proxy address in `ip:port` format (e.g. `127.0.0.1:9050`)
- `REQUEST_TIMEOUT` — request timeout in seconds (default 300)

Each `MD_FILES` entry also supports:
- `exclude_concepts` — string of concepts to skip
- `high_priority_rules` — array of override rules
- `min_lines` — minimum output lines (default 0)

## Usage

```bash
php generate-documentation.php
```

or

```bash
php generate-documentation.php --config .config.php
```

or

```bash
php generate-documentation.php --config /path/to/.config.php
```

The tool prompts you to:
1. **Select a model** — choose from all available models or free-only list
2. **Pick targets** — select one or more documentation targets (comma-separated numbers)
3. **Watch it work** — each step (Read Rules, Read Files, Prepare, Write, Review) runs with retry support

Output saves to `OUT_DIR` with a `.md` extension. A footer includes the model used, date, and prompt version.

## Use Cases

- **Project onboarding** — generate a README and API docs so new contributors understand the codebase immediately.
- **API documentation** — point it at your controller and model files, get complete endpoint and data model references.
- **Configuration references** — document every config key, its defaults, and what happens when it's missing.
- **Architecture guides** — explain system design, flows, and component interactions from source code alone.
- **Compliance docs** — capture security considerations, error handling, and operational edge cases automatically.
- **Living documentation** — regenerate whenever source files change; the tool detects modifications and flags what's new.

## How It Works Under the Hood

The generator runs a five-step AI pipeline:

1. **Read Documentation Rules** — loads the style guide and validates understanding with a success string.
2. **Read Relevant Files** — feeds source code into the conversation so the AI builds an accurate mental model.
3. **Prepare Documentation Task** — maps architecture, features, flows, and edge cases before writing begins.
4. **Start Writing** — produces the full markdown content with enforced minimum line counts.
5. **Review & Perfect** — re-reads sources and refines the output for accuracy and completeness.

Each step retries on failure. On error you choose to retry, switch the AI model, or abort. Rate limits trigger automatic wait-and-retry logic using the `X-RateLimit-Reset` header.

## Command-Line Arguments

- `-c / --config <path>` — specify a custom configuration file path

```bash
php generate-documentation.php --config /path/to/config.php
```

## Tips

- **Start with free models** for quick drafts, then switch to a stronger model for final output.
- **Set `min_lines`** to prevent shallow, incomplete docs.
- **Use `exclude_concepts`** to keep the AI focused when certain topics are out of scope.
- **Commit your `.history.json`** alongside docs to track what's been generated over time.
- **Keep prompts modular** — edit `prompts/*.txt` to fine-tune how the AI thinks about your code.

## What Makes It Different

Most AI doc generators produce generic summaries. This tool enforces a rigorous style guide, runs multiple verification passes, and tracks source changes so your documentation stays accurate as your code evolves. Every section exists for a reason. Nothing is filler.

---

*This is the single source of truth for AI Docs Generator.*

---
*Generated with `baidu/cobuddy:free` on 2026-05-19 (prompts v2026-05-19 03:54:48)*
