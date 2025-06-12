<?php

namespace Deployer;

// Default configuration
set('deploy_path', '~');
set('keep_releases', 3);

// Load project configuration to use in custom tasks.
desc('Load project configuration to use in custom tasks');
task('lameco:load', function () {
    writeln('Loading project configuration...');

    // Detect project type based on key files.
    if (file_exists('bin/console') && file_exists('src/Kernel.php')) {
        $projectType = 'symfony';
        $dumpDir = 'var';
        $publicDir = 'public';
    } elseif (file_exists('craft')) {
        $projectType = 'craftcms';
        $dumpDir = 'storage';
        $publicDir = 'web';
    } elseif (file_exists('artisan')) {
        $projectType = 'laravel';
        $dumpDir = 'storage';
        $publicDir = 'public';
    } else {
        throw new \RuntimeException('Unknown project type: cannot determine from current directory.');
    }

    set('lameco_project_type', $projectType);
    writeln('Project type detected: ' . $projectType);

    set('lameco_dump_dir', $dumpDir);
    writeln('Dump directory set to: ' . $dumpDir);

    set('lameco_public_dir', $publicDir);
    writeln('Public directory set to: ' . $publicDir);
})->once();

// Prompt to deploy all hosts with the same stage if applicable
desc('Prompt to deploy all hosts with the same stage if applicable');
task('lameco:stage_prompt', function () {
    $selectedHost = currentHost();
    if (!$selectedHost) {
        return;
    }

    $stage = $selectedHost->getLabels()['stage'] ?? null;
    if (!$stage) {
        return;
    }

    // Get all defined hosts from Deployer config.
    $allHosts = Deployer::get()->hosts;
    $hostsWithStage = [];
    foreach ($allHosts as $host) {
        if (($host->getLabels()['stage'] ?? null) === $stage) {
            $hostsWithStage[] = $host->getAlias();
        }
    }

    // Only prompt if there are multiple hosts with this stage and not all are already selected.
    if (count($hostsWithStage) > 1 && count($allHosts) !== count($hostsWithStage)) {
        info('Host ' . $selectedHost->getAlias() . ' (' . $selectedHost->getHostname() . ') has stage ' . $stage);
        $confirmation = askConfirmation('Do you want to deploy to all hosts with stage ' . $stage . '?', false);
        if ($confirmation) {
            set('selected_hosts', $hostsWithStage);
            info('Deploying to all hosts with stage ' . $stage);
        }
    }
});

// Download remote database and import locally.
desc('Download remote database and import locally');
task('lameco:db_download', function () {
    within('{{deploy_path}}/shared', function () {
        writeln('Reading remote .env file...');
        $remoteEnvContent = run('cat .env');
        $remoteEnv = fetchEnv($remoteEnvContent);

        [$remoteDatabaseUser, $remoteDatabasePassword, $remoteDatabaseName] = extractDbCredentials($remoteEnv);

        if (!isset($remoteDatabaseUser, $remoteDatabasePassword, $remoteDatabaseName)) {
            error('Could not extract remote database credentials.');
            return;
        }

        $dumpDir = get('lameco_dump_dir');
        $dumpFile = 'current_' . $remoteDatabaseName . '.sql.gz';
        set('dump_file', $dumpFile);

        $remotePath = '~/' . $dumpFile;
        $localPath = $dumpDir . '/' . $dumpFile;

        writeln('Creating remote database dump...');
        run('mysqldump --quick --single-transaction -u ' . $remoteDatabaseUser . ' -p' . $remoteDatabasePassword . ' ' . $remoteDatabaseName . ' | gzip > ' . $remotePath);

        writeln('Downloading database dump to local path: ' . $localPath . '...');
        download($remotePath, $localPath);

        writeln('Removing remote database dump...');
        run('rm ' . $remotePath);
    });

    $dumpFile = get('dump_file');
    $dumpDir = get('lameco_dump_dir');
    $localPath = $dumpDir . '/' . $dumpFile;

    writeln('Importing database from local dump: ' . $localPath . '...');

    writeln('Reading local .env file...');
    $localEnvContent = file_get_contents('.env');
    $localEnv = fetchEnv($localEnvContent);

    [$localDatabaseUser, $localDatabasePassword, $localDatabaseName] = extractDbCredentials($localEnv);

    if (!isset($localDatabaseUser, $localDatabasePassword, $localDatabaseName)) {
        error('Could not extract local database credentials.');
        return;
    }

    writeln('Creating local database if it does not exist...');
    runLocally('mysql -u ' . $localDatabaseUser . ' -p' . $localDatabasePassword . ' -e \'DROP DATABASE IF EXISTS ' . $localDatabaseName . '; CREATE DATABASE ' . $localDatabaseName . ';\'');

    writeln('Importing database dump into local database...');
    runLocally('gunzip -c ' . $localPath . ' | mysql -u ' . $localDatabaseUser . ' -p' . $localDatabasePassword . ' ' . $localDatabaseName);

    writeln('Removing local dump file...');
    runLocally('rm ' . $localPath);
});

// Download remote database credentials.
desc('Download remote database credentials');
task('lameco:db_credentials', function () {
    within('{{deploy_path}}/shared', function () {
        $envContent = run('cat .env');
        $env = fetchEnv($envContent);

        [$remoteDatabaseUser, $remoteDatabasePassword] = extractDbCredentials($env);

        if (!isset($remoteDatabaseUser, $remoteDatabasePassword)) {
            writeln('Could not extract remote database credentials.');
            return;
        }

        writeln('Remote database username: ' . $remoteDatabaseUser);
        writeln('Remote database password: ' . $remoteDatabasePassword);
    });
});

// Download directories from remote to local.
desc('Download directories from remote to local');
task('lameco:download', function () {
    $publicDir = get('lameco_public_dir');

    $defaultDownloadDirs = [
        $publicDir . '/uploads',
    ];

    if (get('lameco_project_type') === 'craftcms') {
        $defaultDownloadDirs[] = 'translations';
    }

    $downloadDirs = get('lameco_download_dirs', $defaultDownloadDirs);

    writeln('Downloading directories from remote to local...');

    foreach ($downloadDirs as $dir) {
        $remoteDir = '{{deploy_path}}/shared/' . $dir . '/';
        $localDir = $dir . '/';

        writeln('Downloading directory: ' . $dir . '...');
        download($remoteDir, $localDir);
    }
});

// Upload directories from local to remote.
desc('Upload directories from local to remote');
task('lameco:upload', function () {
    $publicDir = get('lameco_public_dir');

    $defaultUploadDirs = [
        $publicDir . '/uploads',
    ];

    if (get('lameco_project_type') === 'craftcms') {
        $defaultUploadDirs[] = 'translations';
    }

    $uploadDirs = get('lameco_upload_dirs', $defaultUploadDirs);

    writeln('Uploading directories from local to remote...');

    foreach ($uploadDirs as $dir) {
        $localDir = $dir . '/';
        $remoteDir = '{{deploy_path}}/shared/' . $dir;

        writeln('Uploading directory: ' . $dir . '...');
        upload($localDir, $remoteDir);
    }
});

// Build local assets.
desc('Build local assets');
task('lameco:build_assets', function () {
    writeln('Loading Node.js version from .nvmrc...');

    if (!file_exists('.nvmrc')) {
        throw new \RuntimeException('.nvmrc file not found.');
    }

    $nodeVersion = trim(file_get_contents('.nvmrc'));

    writeln('Using Node.js version: ' . $nodeVersion);

    writeln('Checking if Node.js version is already installed...');

    $nodeIsInstalled = testLocally('source $HOME/.nvm/nvm.sh && nvm ls ' . $nodeVersion . ' | grep -q ' . $nodeVersion);

    if ($nodeIsInstalled) {
        writeln('Node.js version is already installed. Using it...');
        runLocally('source $HOME/.nvm/nvm.sh && nvm use ' . $nodeVersion);
    } else {
        writeln('Node.js version is not installed. Installing...');
        runLocally('source $HOME/.nvm/nvm.sh && nvm install ' . $nodeVersion);
    }

    if (nodeSupportsCorepack($nodeVersion)) {
        writeln('Enabling Corepack...');
        runLocally('corepack enable');
    }

    writeln('Installing dependencies...');
    runLocally('yarn install');

    writeln('Building assets...');
    runLocally('yarn build');
});

// Upload built assets to remote.
desc('Upload built assets to remote');
task('lameco:upload_assets', function () {
    $publicDir = get('lameco_public_dir');

    $defaultAssetsDirs = [
        $publicDir . '/dist',
    ];

    $assetsDirs = get('lameco_assets_dirs', $defaultAssetsDirs);

    writeln('Uploading built assets from local to remote...');

    foreach ($assetsDirs as $dir) {
        $localDir = $dir . '/';
        $remoteDir = '{{release_path}}/' . $dir;

        writeln('Uploading assets directory: ' . $dir . '...');
        upload($localDir, $remoteDir);
    }
});

// Restart php-fpm service.
desc('Restart php-fpm service');
task('lameco:restart_php', function () {
    if (!get('lameco_restart_php', true)) {
        writeln('php-fpm is not enabled for this project.');
        return;
    }

    writeln('Restarting php-fpm service...');

    $config = 'php-fpm-' . get('http_user') . '.service';

    writeln('Restarting php-fpm config: ' . $config . '...');
    run('sudo systemctl restart ' . $config);
});

// Restart supervisor.
desc('Restart supervisor');
task('lameco:restart_supervisor', function () {
    if (!get('lameco_restart_supervisor', true)) {
        writeln('Supervisor is not enabled for this project.');
        return;
    }

    writeln('Restarting supervisor...');

    $supervisorConfigs = get('lameco_supervisor_configs', [
        get('http_user') . '.conf',
    ]);

    foreach ($supervisorConfigs as $config) {
        writeln('Restarting supervisor config: ' . $config . '...');
        run('supervisorctl -c /etc/projects/supervisor/' . $config . ' restart all');
    }
});

before('deploy', 'lameco:load');
before('deploy', 'lameco:stage_prompt');

before('lameco:db_download', 'lameco:load');
before('lameco:db_credentials', 'lameco:load');
before('lameco:download', 'lameco:load');
before('lameco:upload', 'lameco:load');

before('deploy:symlink', 'lameco:build_assets');
after('lameco:build_assets', 'lameco:upload_assets');

after('deploy:cleanup', 'lameco:restart_php');
after('deploy:cleanup', 'lameco:restart_supervisor');

/**
 * Parse .env content into an associative array.
 */
function fetchEnv($envContent): array
{
    $env = [];
    foreach (explode("\n", $envContent) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (preg_match('/^([^=]+)=(?:[\'"]?)(.+?)(?:[\'"]?)$/', $line, $kv)) {
            $env[$kv[1]] = $kv[2];
        }
    }
    return $env;
}

/**
 * Extract DB credentials from env array.
 * Supports DATABASE_URL (e.g. Symfony), CRAFT_DB_* (e.g. Craft CMS), and Laravel style.
 */
function extractDbCredentials($env): array
{
    if (!empty($env['DATABASE_URL'])) {
        if (preg_match('|mysql://([^:]+):([^@]+)@[^/]+/([^?]+)|', $env['DATABASE_URL'], $dbMatch)) {
            return [$dbMatch[1], $dbMatch[2], $dbMatch[3]];
        }
    } elseif (!empty($env['CRAFT_DB_DATABASE'])) {
        return [
            $env['CRAFT_DB_USER'] ?? null,
            $env['CRAFT_DB_PASSWORD'] ?? null,
            $env['CRAFT_DB_DATABASE']
        ];
    } elseif (!empty($env['DB_DATABASE'])) { // Fallback for Laravel style
        return [
            $env['DB_USER'] ?? ($env['DB_USERNAME'] ?? null),
            $env['DB_PASSWORD'] ?? null,
            $env['DB_DATABASE']
        ];
    }
    return [null, null, null];
}

/**
 * Determine if the given Node.js version supports Corepack.
 */
function nodeSupportsCorepack($versionString): bool
{
    if (preg_match('/v(\d+)\.(\d+)\.(\d+)/', $versionString, $m)) {
        $major = (int)$m[1];
        $minor = (int)$m[2];
        // Corepack from 14.19+, 16.9+, or >16
        return
            ($major === 14 && $minor >= 19) ||
            ($major === 16 && $minor >= 9) ||
            ($major > 16);
    }
    return false;
}
