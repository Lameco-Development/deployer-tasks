<?php

namespace Deployer;

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

require 'contrib/crontab.php';

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

        $dumpFile = 'current_' . $remoteDatabaseName . '.sql.gz';
        set('dump_file', $dumpFile);

        $remotePath = '~/{{dump_file}}';
        $localPath = '{{lameco_dump_dir}}/{{dump_file}}';

        writeln('Creating remote database dump...');
        run('mysqldump --quick --single-transaction -u ' . $remoteDatabaseUser . ' -p' . $remoteDatabasePassword . ' ' . $remoteDatabaseName . ' | gzip > ' . $remotePath);

        writeln('Downloading database dump to local path: ' . $localPath . '...');
        download($remotePath, $localPath);

        writeln('Removing remote database dump...');
        run('rm ' . $remotePath);
    });

    $localPath = '{{lameco_dump_dir}}/{{dump_file}}';

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
    $downloadDirs = get('lameco_download_dirs');

    if (empty($downloadDirs)) {
        writeln('No download directories configured.');
        return;
    }

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
    $uploadDirs = get('lameco_upload_dirs');

    if (empty($uploadDirs)) {
        writeln('No upload directories configured.');
        return;
    }

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
    $assetsDirs = get('lameco_assets_dirs');

    if (empty($assetsDirs)) {
        writeln('No assets directories configured.');
        return;
    }

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
    if (!get('lameco_restart_php')) {
        writeln('php-fpm is not enabled for this project.');
        return;
    }

    $config = get('lameco_php_config');

    if (!$config) {
        writeln('No php-fpm config configured.');
        return;
    }

    writeln('Restarting php-fpm service...');

    writeln('Restarting php-fpm config: ' . $config . '...');
    run('sudo systemctl restart ' . $config);
});

// Restart supervisor.
desc('Restart supervisor');
task('lameco:restart_supervisor', function () {
    if (!get('lameco_restart_supervisor')) {
        writeln('Supervisor is not enabled for this project.');
        return;
    }

    $supervisorConfigs = get('lameco_supervisor_configs');

    if (empty($supervisorConfigs)) {
        writeln('No supervisor configs configured.');
        return;
    }

    writeln('Restarting supervisor...');

    foreach ($supervisorConfigs as $config) {
        writeln('Restarting supervisor config: ' . $config . '...');
        run('supervisorctl -c /etc/projects/supervisor/' . $config . ' restart all');
    }
});

before('deploy', 'lameco:stage_prompt');

before('deploy:symlink', 'lameco:build_assets');
after('lameco:build_assets', 'lameco:upload_assets');

after('deploy:cleanup', 'lameco:restart_php');
after('deploy:cleanup', 'lameco:restart_supervisor');

after('deploy:success', 'crontab:sync');
