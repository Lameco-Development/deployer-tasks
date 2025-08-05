# Deployer Tasks for Lameco

A collection of common tasks for [Deployer](https://deployer.org/) to streamline deployment workflows.

## Requirements

- PHP 8.4 or higher
- Deployer 7.0 or higher

## Installation

```bash
composer require lameco/deployer-tasks --dev
```

## Available Tasks

### lameco:load

Loads project configuration for use in custom tasks.

- Detects project type (`symfony`, `kunstmaan`, `craftcms`, `laravel`) by checking for key files and composer packages.
- Sets `lameco_project_type`, `lameco_dump_dir`, and `lameco_public_dir` accordingly.
- These variables are used in default directory settings and can be overridden in your `deploy.php`.

---

### lameco:stage_prompt

Prompts to deploy all hosts with the same stage if applicable.

- If a host has a `stage` set, and there are other hosts with the same `stage`, you will be prompted to deploy to all hosts with that stage. This helps prevent accidental partial deployments and ensures consistency across environments.

---

### lameco:db_download

Downloads the remote database and imports it locally.

- Reads the remote `.env` file to extract DB credentials (supports Symfony, Craft CMS, Laravel formats, and `DATABASE_URL`).
- Creates a compressed database dump on the remote server.
- Downloads the dump to a local directory (as determined by `lameco_dump_dir`).
- Reads the local `.env` for local DB credentials.
- Drops and recreates the local database, then imports the dump.
- Cleans up dump files both remotely and locally.

---

### lameco:db_credentials

Displays remote database credentials.

- Reads the remote `.env` file.
- Extracts and prints the remote DB username and password.

---

### lameco:download

Downloads directories from remote to local.

- Downloads each directory in `lameco_download_dirs` from `{{deploy_path}}/shared` on the remote to the same path locally.
- By default, downloads `['{{lameco_public_dir}}/uploads']`.
- For Craft CMS projects, also downloads the `translations` directory by default.

---

### lameco:upload

Uploads directories from local to remote.

- Uploads each directory in `lameco_upload_dirs` from local to `{{deploy_path}}/shared` on the remote.
- By default, uploads `['{{lameco_public_dir}}/uploads']`.
- For Craft CMS projects, also uploads the `translations` directory by default.

---

### lameco:build_assets

Builds local assets.

- Loads Node.js version from `.nvmrc`.
- Installs the correct Node.js version using nvm if not already installed.
- Enables Corepack if supported by Node.js version (Node 14.19+, 16.9+, or >16).
- Installs dependencies with yarn.
- Builds assets with yarn.

---

### lameco:upload_assets

Uploads built assets to remote.

- Uploads each directory in `lameco_assets_dirs` from local to `{{release_path}}` on the remote.
- Uploads each file in `lameco_assets_files` from local to `{{release_path}}` on the remote.
- Default: uploads `['{{lameco_public_dir}}/dist']` for directories, empty array for files.

---

### lameco:restart_php

Restarts php-fpm service.

- Restarts the service specified by `lameco_php_config` (default: `php-fpm-{{http_user}}.service`) on the remote server.
- Skips if `lameco_restart_php` is false.

---

### lameco:restart_supervisor

Restarts supervisor.

- Restarts each supervisor config in `lameco_supervisor_configs` using `supervisorctl`.
- Skips if `lameco_restart_supervisor` is false.

---

### crontab:sync

Synchronizes crontab jobs for the project.

- Automatically sets up crontab jobs based on project type and detected plugins (e.g., Craft CMS with Blitz or Formie).
- Jobs are defined in the `crontab:jobs` configuration, which is dynamically generated for Craft CMS projects.

---

### lameco:cleanup_blitz_cache

Cleans up Blitz cache from old releases for Craft CMS projects.

- Only runs for Craft CMS projects (`lameco_project_type === 'craftcms'`).
- Removes the `web/cache/blitz` directory from all previous releases except the current active one.
- Runs after successful deployment to ensure the deployment is complete before cleanup.
- Helps prevent storage bloat and ensures outdated cache files do not accumulate in old releases.
- Can be disabled by setting `lameco_cleanup_blitz_cache` to false.
- **Why this is needed for Craft CMS**: Blitz is a static cache plugin for Craft CMS that stores cached files in `web/cache/blitz`. During deployment, these cache files can accumulate across multiple releases, consuming unnecessary disk space. Since the cache is environment-specific and tied to the current release, old cache files serve no purpose and should be cleaned up to maintain optimal server performance.

---

## Parameters

- `lameco_project_type`: Project type (auto-detected: `symfony`, `kunstmaan`, `craftcms`, `laravel`)
- `lameco_dump_dir`: Directory for database dumps (auto-set by project type)
- `lameco_public_dir`: Public directory (auto-set by project type)
- `lameco_download_dirs`: Directories to download from remote (default: `['{{lameco_public_dir}}/uploads']`, plus `translations` for Craft CMS)
- `lameco_upload_dirs`: Directories to upload to remote (default: `['{{lameco_public_dir}}/uploads']`, plus `translations` for Craft CMS)
- `lameco_assets_dirs`: Asset directories to upload (default: `['{{lameco_public_dir}}/dist']`)
- `lameco_assets_files`: Asset files to upload (default: `[]`)
- `lameco_restart_php`: Enable/disable PHP-FPM restart (default: true)
- `lameco_php_config`: PHP-FPM systemd service name (default: `php-fpm-{{http_user}}.service`)
- `lameco_restart_supervisor`: Enable/disable supervisor restart (default: true)
- `lameco_supervisor_configs`: Supervisor config files (default: `[get('http_user') . '.conf']`)
- `lameco_cleanup_blitz_cache`: Enable/disable Blitz cache cleanup from old releases for Craft CMS (default: true)
- `http_user`: User running PHP-FPM or supervisor

## Task Dependencies

- `lameco:load` runs before `deploy`
- `lameco:stage_prompt` runs before `deploy`
- `lameco:db_download`, `lameco:db_credentials`, `lameco:download`, and `lameco:upload` depend on `lameco:load`
- `lameco:build_assets` runs before `deploy:symlink`
- `lameco:upload_assets` runs after `lameco:build_assets`
- `lameco:restart_php` and `lameco:restart_supervisor` run after `deploy:cleanup`
- `crontab:sync` runs after `deploy:success`
- `lameco:cleanup_blitz_cache` runs after `deploy:success` for Craft CMS projects

## Usage

Include the tasks in your `deploy.php` file:

```php
require 'vendor/lameco/deployer-tasks/src/tasks.php';

// Override or extend parameters if needed
set('lameco_assets_dirs', ['public/build']);
add('lameco_supervisor_configs', ['app.conf', 'queue.conf']);
set('lameco_php_config', 'php-fpm-customuser.service');
```

## Notes

- Project type detection is automatic and supports Symfony, Kunstmaan, Craft CMS, and Laravel.
- Database credential extraction supports `.env` formats for Symfony (`DATABASE_URL`), Craft CMS (`CRAFT_DB_*`), and Laravel (`DB_*`).
- Asset build and upload tasks expect a working Node.js/yarn setup and `.nvmrc` file.
- Supervisor and PHP-FPM restarts are configurable and can be disabled per project.

## License

This package is open-sourced software licensed under the MIT license.

