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

**Logic:**  
- Detects project type (Symfony, Craft CMS, Laravel) by checking for key files.
- Sets `lameco_project_type`, `lameco_dump_dir`, and `lameco_public_dir` accordingly.

**Parameters:**  
- `lameco_project_type` - Project type (auto-detected)
- `lameco_dump_dir` - Dump directory (auto-set)
- `lameco_public_dir` - Public directory (auto-set)

---

### lameco:db_download

Downloads the remote database and imports it locally.

**Logic:**  
- Reads the remote `.env` file to extract DB credentials.
- Creates a compressed database dump on the remote server.
- Downloads the dump to a local directory (as determined by `lameco_dump_dir`).
- Reads the local `.env` for local DB credentials.
- Drops and recreates the local database, then imports the dump.
- Cleans up dump files both remotely and locally.

**Parameters:**  
- `lameco_dump_dir` - Directory for database dumps (auto-set by project type).

---

### lameco:db_credentials

Displays remote database credentials.

**Logic:**  
- Reads the remote `.env` file.
- Extracts and prints the remote DB username and password.

**Parameters:**  
- None

---

### lameco:download

Downloads directories from remote to local.

**Logic:**  
- Downloads each directory in `lameco_download_dirs` from `{{deploy_path}}/shared` on the remote to the same path locally.
- By default, downloads `[$publicDir . '/uploads']`.
- For Craft CMS projects, also downloads the `translations` directory by default.

**Parameters:**  
- `lameco_download_dirs` - Array of directories to download (default: `[$publicDir . '/uploads']` or `[$publicDir . '/uploads', 'translations']` for Craft CMS)
- `lameco_public_dir` - Public directory (auto-set by project type)

---

### lameco:upload

Uploads directories from local to remote.

**Logic:**  
- Uploads each directory in `lameco_upload_dirs` from local to `{{deploy_path}}/shared` on the remote.
- By default, uploads `[$publicDir . '/uploads']`.
- For Craft CMS projects, also uploads the `translations` directory by default.

**Parameters:**  
- `lameco_upload_dirs` - Array of directories to upload (default: `[$publicDir . '/uploads']` or `[$publicDir . '/uploads', 'translations']` for Craft CMS)
- `lameco_public_dir` - Public directory (auto-set by project type)

---

### lameco:build_assets

Builds local assets.

**Logic:**  
- Loads Node.js version from `.nvmrc`.
- Installs the correct Node.js version using nvm if not already installed.
- Enables corepack if supported by Node.js version.
- Installs dependencies with yarn.
- Builds assets with yarn.

**Parameters:**  
- None

---

### lameco:upload_assets

Uploads built assets to remote.

**Logic:**  
- Uploads each directory in `lameco_assets_dirs` from local to `{{release_path}}` on the remote.
- Default: uploads `[$publicDir . '/dist']`.

**Parameters:**  
- `lameco_assets_dirs` - Array of asset directories to upload (default: `[$publicDir . '/dist']`)
- `lameco_public_dir` - Public directory (auto-set by project type)

---

### lameco:restart_php

Restarts php-fpm service.

**Logic:**  
- Restarts the `php-fpm-<http_user>.service` systemd service on the remote server.
- Skips if `lameco_restart_php` is false.

**Parameters:**  
- `http_user` - User running PHP-FPM
- `lameco_restart_php` - Enable/disable PHP-FPM restart (default: true)

---

### lameco:restart_supervisor

Restarts supervisor.

**Logic:**  
- Restarts each supervisor config in `lameco_supervisor_configs` using `supervisorctl`.
- Skips if `lameco_restart_supervisor` is false.

**Parameters:**  
- `lameco_supervisor_configs` - Array of supervisor config files (default: `[get('http_user') . '.conf']`)
- `http_user` - User running supervisor
- `lameco_restart_supervisor` - Enable/disable supervisor restart (default: true)

---

## Task Dependencies

- `lameco:db_download`, `lameco:db_credentials`, `lameco:download`, and `lameco:upload` depend on `lameco:load`
- `lameco:build_assets` runs before `deploy:symlink`
- `lameco:upload_assets` runs after `lameco:build_assets`
- `lameco:restart_php` and `lameco:restart_supervisor` run after `deploy:cleanup`

## Usage

Include the tasks in your `deploy.php` file:

```php
require 'vendor/lameco/deployer-tasks/src/tasks.php';

// Override parameters if needed
set('lameco_assets_dirs', ['public/build']);
set('lameco_supervisor_configs', ['app.conf', 'queue.conf']);
```

## License

This package is open-sourced software licensed under the MIT license.

