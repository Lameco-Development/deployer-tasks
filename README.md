# Deployer Tasks for Lameco

A collection of common tasks for [Deployer](https://deployer.org/) to streamline deployment workflows.

## Requirements

- PHP 8.4 or higher
- Deployer 7.0 or higher

## Installation

```bash
composer require lameco/deployer-tasks
```

## Available Tasks

### lameco:db_download

Downloads remote database and imports it locally.

**Description:** This task connects to the remote server, creates a database dump, downloads it to the local environment, and imports it into the local database.

**Parameters:**
- `lameco_dump_dir` - Directory where database dumps are stored (automatically set based on project type)

### lameco:db_credentials

Downloads and displays remote database credentials.

**Description:** Retrieves database credentials from the remote server's .env file and displays them.

**Parameters:** None

### lameco:download

Downloads directories from remote to local.

**Description:** Downloads specified directories from the remote server to the local environment.

**Parameters:**
- `lameco_download_dirs` - Array of directories to download (default: `[$publicDir . '/uploads']`)
- `lameco_public_dir` - Public directory of the project (automatically set based on project type)

### lameco:upload

Uploads directories from local to remote.

**Description:** Uploads specified directories from the local environment to the remote server.

**Parameters:**
- `lameco_upload_dirs` - Array of directories to upload (default: `[$publicDir . '/uploads']`)
- `lameco_public_dir` - Public directory of the project (automatically set based on project type)

### lameco:load

Loads project configuration to use in custom tasks.

**Description:** Detects the project type (Symfony, Craft CMS, or Laravel) and sets configuration variables accordingly.

**Parameters:**
- `lameco_project_type` - Type of project (automatically detected)
- `lameco_dump_dir` - Directory where database dumps are stored (automatically set based on project type)
- `lameco_public_dir` - Public directory of the project (automatically set based on project type)

### lameco:build_assets

Builds local assets.

**Description:** Installs nvm, installs dependencies with yarn, and builds assets.

**Parameters:** None

### lameco:upload_assets

Uploads built assets to remote.

**Description:** Uploads built assets from the local environment to the remote server.

**Parameters:**
- `lameco_assets_dir` - Directory containing built assets (default: `'web/dist/'`)

### lameco:restart_php

Restarts php-fpm service.

**Description:** Restarts the php-fpm service on the remote server.

**Parameters:**
- `http_user` - User running the PHP-FPM service

### lameco:restart_supervisor

Restarts supervisor.

**Description:** Restarts supervisor on the remote server.

**Parameters:**
- `lameco_supervisor_configs` - Array of supervisor configuration files (default: `[get('http_user') . '.conf']`)
- `http_user` - User running the supervisor service

## Task Dependencies

- `lameco:db_download`, `lameco:db_credentials`, `lameco:download`, and `lameco:upload` depend on `lameco:load`
- `lameco:build_assets` runs before `deploy:symlink`
- `lameco:upload_assets` runs after `lameco:build_assets`
- `lameco:restart_php` and `lameco:restart_supervisor` run after `deploy:cleanup`

## Usage

Include the tasks in your `deploy.php` file:

```php
require 'vendor/autoload.php';

// Configure your deployment
set('repository', 'git@github.com:your/repository.git');
// ... other configuration

// Override parameters if needed
set('lameco_assets_dir', 'public/build/');
set('lameco_supervisor_configs', ['app.conf', 'queue.conf']);

// Use the tasks in your deployment workflow
after('deploy:failed', 'deploy:unlock');
```

## License

This package is open-sourced software licensed under the MIT license.
