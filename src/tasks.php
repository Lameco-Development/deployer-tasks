<?php

namespace Deployer;

desc('Download remote database and import locally');
task('lameco:db_download', function () {
    within('{{deploy_path}}/shared', function () {
        writeln('Reading remote .env...');
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

        writeln('Creating database dump...');
        run('mysqldump --quick --single-transaction -u ' . $remoteDatabaseUser . ' -p' . $remoteDatabasePassword . ' ' . $remoteDatabaseName . ' | gzip > ' . $remotePath);

        writeln('Downloading database dump to local: ' . $localPath);
        download($remotePath, $localPath);

        writeln('Removing remote database dump...');
        run('rm ' . $remotePath);
    });

    $dumpFile = get('dump_file');
    $dumpDir = get('lameco_dump_dir');
    $localPath = $dumpDir . '/' . $dumpFile;

    writeln('Importing database from ' . $localPath . '...');

    writeln('Reading local .env...');
    $localEnvContent = file_get_contents('.env');
    $localEnv = fetchEnv($localEnvContent);

    [$localDatabaseUser, $localDatabasePassword, $localDatabaseName] = extractDbCredentials($localEnv);

    if (!isset($localDatabaseUser, $localDatabasePassword, $localDatabaseName)) {
        error('Could not extract local database credentials.');
        return;
    }

    writeln('Creating local database if it does not exist...');
    runLocally('mysql -u ' . $localDatabaseUser . ' -p' . $localDatabasePassword . ' -e \'DROP DATABASE IF EXISTS ' . $localDatabaseName . '; CREATE DATABASE ' . $localDatabaseName . ';\'');

    writeln('Importing database dump...');
    runLocally('gunzip -c ' . $localPath . ' | mysql -u ' . $localDatabaseUser . ' -p' . $localDatabasePassword . ' ' . $localDatabaseName);

    writeln('Removing local dump file...');
    runLocally('rm ' . $localPath);
});

desc('Download remote database credentials');
task('lameco:db_credentials', function () {
    within('{{deploy_path}}/shared', function () {
        $envContent = run('cat .env');
        $env = fetchEnv($envContent);

        [$remoteDatabaseUser, $remoteDatabasePassword] = extractDbCredentials($env);

        if (!isset($remoteDatabaseUser, $remoteDatabasePassword)) {
            writeln('Remote database credentials could not be extracted.');
            return;
        }

        writeln('Username: ' . $remoteDatabaseUser);
        writeln('Password: ' . $remoteDatabasePassword);
    });
});

desc('Download directories from remote to local');
task('lameco:download', function () {
    $publicDir = get('lameco_public_dir');

    $downloadDirs = get('lameco_download_dirs', [
        $publicDir . '/uploads',
    ]);

    writeln('Downloading directories from remote to local...');

    foreach ($downloadDirs as $dir) {
        $remoteDir = '{{deploy_path}}/shared/' . $dir . '/';
        $localDir = $dir . '/';

        writeln('Downloading directory: ' . $dir);
        download($remoteDir, $localDir);
    }
});

desc('Upload directories from local to remote');
task('lameco:upload', function () {
    $publicDir = get('lameco_public_dir');

    $uploadDirs = get('lameco_upload_dirs', [
        $publicDir . '/uploads',
    ]);

    writeln('Uploading directories from local to remote...');

    foreach ($uploadDirs as $dir) {
        $localDir = $dir . '/';
        $remoteDir = '{{deploy_path}}/shared/' . $dir;

        writeln('Uploading directory: ' . $dir);
        upload($localDir, $remoteDir);
    }
});

desc('Load project configuration to use in custom tasks');
task('lameco:load', function () {
    writeln('Loading project configuration...');

    // Detect project type based on key files
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
    writeln('Project type: ' . $projectType);

    set('lameco_dump_dir', $dumpDir);
    writeln('Dump directory: ' . $dumpDir);

    set('lameco_public_dir', $publicDir);
    writeln('Public directory: ' . $publicDir);
})->once();

desc('Build local assets');
task('lameco:build_assets', function () {
    writeln('Installing nvm...');
    runLocally('source $HOME/.nvm/nvm.sh && nvm install');

    $nodeVersion = runLocally('source $HOME/.nvm/nvm.sh && node --version');

    if (nodeSupportsCorepack($nodeVersion)) {
        writeln('Enabling corepack...');
        runLocally('corepack enable');
    } else {
        writeln('Corepack not available for this Node.js version: ' . $nodeVersion);
    }

    writeln('Installing dependencies...');
    runLocally('yarn install');

    writeln('Building assets...');
    runLocally('yarn build');
});

desc('Upload built assets to remote');
task('lameco:upload_assets', function () {
    $publicDir = get('lameco_public_dir');

    $assetsDirs = get('lameco_assets_dirs', [
        $publicDir . '/dist',
    ]);

    writeln('Uploading built assets from local to remote...');

    foreach ($assetsDirs as $dir) {
        $localDir = $dir . '/';
        $remoteDir = '{{release_path}}/' . $dir;

        upload($localDir, $remoteDir);
    }
});

desc('Restart php-fpm service');
task('lameco:restart_php', function () {
    if (!get('lameco_restart_php', true)) {
        writeln('Php-fpm is not enabled for this project.');
        return;
    }

    writeln('Restarting php-fpm service...');

    $config = 'php-fpm-' . get('http_user') . '.service';

    writeln('Restarting php-fpm config: ' . $config);
    run('sudo systemctl restart ' . $config);
});

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
        writeln('Restarting supervisor config: ' . $config);
        run('supervisorctl -c /etc/projects/supervisor/' . $config . ' restart all');
    }
});

before('deploy', 'lameco:load');
before('lameco:db_download', 'lameco:load');
before('lameco:db_credentials', 'lameco:load');
before('lameco:download', 'lameco:load');
before('lameco:upload', 'lameco:load');

before('deploy:symlink', 'lameco:build_assets');
after('lameco:build_assets', 'lameco:upload_assets');

after('deploy:cleanup', 'lameco:restart_php');
after('deploy:cleanup', 'lameco:restart_supervisor');

/**
 * Parse .env content into associative array.
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
 * Supports DATABASE_URL (e.g. Symfony) and CRAFT_DB_* (e.g. Craft CMS).
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
