# AI Docs Generator

PHP CLI tool for generating documentation with AI.

Reads source files and produces Markdown via OpenRouter models. Enforces single source of truth with complete, concise coverage of all concepts, flows, and edge cases.

## Features

- Free model selection from OpenRouter
- Multi-step AI pipeline with retries: read files, rules, prepare, write, review
- Config-driven targets with min line validation
- Strict style enforcement via modular prompts

## Installation

```bash
git clone https://github.com/bluedev752/ai-docs-generator
cd ai-docs-generator
```

## Configuration

Copy `.config.php.example` to `.config.php` and set:

- `OPENROUTER_API_KEY`
- `SRC_DIR`
- `OUT_DIR`
- `MD_FILES` array with `title`, `relevant_files`, `min_lines`

## Usage

```bash
php generate-documentation.php
```

Select model and target. Output saved to `OUT_DIR`.

## Project Philosophy

No redundancy. Precise language. Complete coverage of concepts, flows, and edge cases.

This document is the single source of truth for the project.
