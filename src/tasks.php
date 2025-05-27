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

desc('Download files from remote to local');
task('lameco:download', function () {
    $publicDir = get('lameco_public_dir');
    $remotePath = '{{deploy_path}}/shared/' . $publicDir . '/uploads/';
    $localPath = $publicDir . '/uploads/';

    writeln('Downloading files from remote to local...');
    download($remotePath, $localPath);
});

desc('Upload local files to remote');
task('lameco:upload', function () {
    $publicDir = get('lameco_public_dir');
    $remotePath = '{{deploy_path}}/shared/' . $publicDir . '/uploads/';
    $localPath = $publicDir . '/uploads/*';

    writeln('Uploading files from local to remote...');
    upload($localPath, $remotePath);
});

desc('Load project configuration to use in custom tasks');
task('lameco:load', function () {
    writeln('Loading project configuration...');

    // Detect project type based on key files
    if (file_exists('bin/console') && file_exists('src/Kernel.php')) {
        $projectType = 'symfony';
    } elseif (file_exists('craft')) {
        $projectType = 'craftcms';
    } elseif (file_exists('artisan')) {
        $projectType = 'laravel';
    } else {
        throw new \RuntimeException('Unknown project type: cannot determine from current directory.');
    }
    set('lameco_project_type', $projectType);
    writeln('Project type: ' . $projectType);

    // Set dump directory based on project type
    $dumpDir = match ($projectType) {
        'symfony' => 'var',
        'craftcms' => 'storage',
        'laravel' => 'storage',
        default => ''
    };
    set('lameco_dump_dir', $dumpDir);
    writeln('Dump directory: ' . $dumpDir);

    // Set public directory based on project type
    $publicDir = match ($projectType) {
        'symfony', 'laravel' => 'public',
        'craftcms' => 'web',
        default => ''
    };
    set('lameco_public_dir', $publicDir);
    writeln('Public directory: ' . $publicDir);
});

desc('Build local assets');
task('lameco:build_assets', function () {
    writeln('Installing nvm...');
    runLocally('source $HOME/.nvm/nvm.sh && nvm install');

    writeln('Installing dependencies...');
    runLocally('corepack yarn install');

    writeln('Building assets...');
    runLocally('corepack yarn build');
});

desc('Upload built assets to remote');
task('lameco:upload_assets', function () {
    // Get the source directory from configuration or use default
    $sourceDir = get('lameco_assets_dir', 'web/dist/');

    // Construct the destination path by prefixing the release_path to the source directory
    $destDir = '{{release_path}}/' . $sourceDir;

    writeln('Uploading built assets from ' . $sourceDir . ' to remote ' . $destDir . '...');
    upload($sourceDir, $destDir, [
        'options' => ['--delete'],
        'exclude' => ['node_modules', '.git', 'yarn.lock', 'package.json']
    ]);
});

desc('Restart php-fpm service');
task('lameco:restart_php', function () {
    writeln('Restarting php-fpm service...');
    run('sudo systemctl restart php-fpm-' . get('http_user') . '.service');
});

desc('Restart supervisor');
task('lameco:restart_supervisor', function () {
    writeln('Restarting supervisor...');

    $supervisorConfigs = get('lameco_supervisor_configs', [
        get('http_user') . '.conf',
    ]);

    foreach ($supervisorConfigs as $config) {
        writeln('Restarting supervisor config: ' . $config);
        run('supervisorctl -c /etc/projects/supervisor/' . $config . ' restart all');
    }
});

before('lameco:db_download', 'lameco:load');
before('lameco:db_credentials', 'lameco:load');
before('lameco:download', 'lameco:load');
before('lameco:upload', 'lameco:load');

before('deploy:symlink', 'lameco:build_assets');
before('deploy:symlink', 'lameco:upload_assets');

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
            $env['CRAFT_DB_DATABASE'] ?? null
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
