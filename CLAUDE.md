# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project Overview

PHP Composer package (`lameco/deployer-tasks`) providing reusable deployment tasks for [Deployer](https://deployer.org/). Supports Symfony, Kunstmaan, Craft CMS, and Laravel projects with tasks for database sync, asset building, file transfers, service restarts, cron management, and staging safety.

## Requirements

- PHP 8.4+
- Deployer 7.0+

## Commands

```bash
# Install dependencies
composer install

# Lint (PSR-12 + clean code rules)
vendor/bin/ecs check src/
vendor/bin/ecs check src/ --fix

# Automated refactoring
vendor/bin/rector process src/

# Static analysis
vendor/bin/phpstan analyse src/
```

## Architecture

All source code lives in `src/` with three files:

- **`tasks.php`** — Deployer task definitions (db sync, assets, deploys, cron, etc.). Tasks hook into Deployer's lifecycle via `before()`/`after()` calls at the bottom of the file.
- **`config.php`** — Default configuration values using Deployer's `set()`. Auto-detects project type and sets framework-specific defaults (dump dirs, public dirs, shared dirs/files).
- **`functions.php`** — Pure helper functions: `.env` parsing (`fetchEnv`), DB credential extraction (`extractDbCredentials`), Node version checks (`nodeSupportsCorepack`), Composer package detection (`composerHasPackage`), staging detection (`isStaging`), cron staggering (`getCronMinute`).

## Code Conventions

- `declare(strict_types=1)` in all files
- Namespace: `Deployer` (functions live in the Deployer namespace)
- Always use `escapeshellarg()` for shell-interpolated values
- ECS enforces PSR-12, common presets, clean code presets, and PHP 8.4 migration rules
- Deployer tasks use `run()` for remote commands and `runLocally()` for local commands
