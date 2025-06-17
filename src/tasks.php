<?php

namespace Deployer;

use Deployer\Exception\GracefulShutdownException;

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/security.php';

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
            info('Deploying to all hosts with stage ' . $stage);
            // Validate stage name to prevent command injection
            $validatedStage = validateStage($stage);
            passthru('dep deploy -n stage=' . safeEscapeShellArg($validatedStage));
            throw new GracefulShutdownException('Done deploying to all hosts');
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
        
        // Create secure MySQL configuration file for remote database
        $remoteMysqlConfig = createSecureMysqlConfig($remoteDatabaseUser, $remoteDatabasePassword);
        
        try {
            // Use --defaults-file to avoid exposing password in command line
            run('mysqldump --defaults-file=' . safeEscapeShellArg($remoteMysqlConfig) . ' --quick --single-transaction ' . safeEscapeShellArg($remoteDatabaseName) . ' | gzip > ' . safeEscapeShellArg($remotePath));
        } finally {
            // Clean up the configuration file
            run('rm -f ' . safeEscapeShellArg($remoteMysqlConfig));
        }

        writeln('Downloading database dump to local path: ' . $localPath . '...');
        download($remotePath, $localPath);

        writeln('Removing remote database dump...');
        run('rm -f ' . safeEscapeShellArg($remotePath));
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
    
    // Create secure MySQL configuration file for local database
    $localMysqlConfig = createSecureMysqlConfig($localDatabaseUser, $localDatabasePassword);
    
    try {
        runLocally('mysql --defaults-file=' . safeEscapeShellArg($localMysqlConfig) . ' -e ' . safeEscapeShellArg('DROP DATABASE IF EXISTS ' . $localDatabaseName . '; CREATE DATABASE ' . $localDatabaseName . ';'));

        writeln('Importing database dump into local database...');
        runLocally('gunzip -c ' . safeEscapeShellArg($localPath) . ' | mysql --defaults-file=' . safeEscapeShellArg($localMysqlConfig) . ' ' . safeEscapeShellArg($localDatabaseName));
    } finally {
        // Clean up the configuration file
        secureUnlink($localMysqlConfig);
    }

    writeln('Removing local dump file...');
    runLocally('rm -f ' . safeEscapeShellArg($localPath));
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

        // Note: Only display username for security reasons
        writeln('Remote database username: ' . $remoteDatabaseUser);
        writeln('Remote database password: [REDACTED FOR SECURITY]');
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
        // Validate directory path to prevent path traversal
        $validatedDir = validateDirectoryPath($dir);
        $remoteDir = '{{deploy_path}}/shared/' . $validatedDir . '/';
        $localDir = $validatedDir . '/';

        writeln('Downloading directory: ' . $validatedDir . '...');
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
        // Validate directory path to prevent path traversal
        $validatedDir = validateDirectoryPath($dir);
        $localDir = $validatedDir . '/';
        $remoteDir = '{{deploy_path}}/shared/' . $validatedDir;

        writeln('Uploading directory: ' . $validatedDir . '...');
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
    
    // Validate Node.js version to prevent command injection
    $validatedNodeVersion = validateNodeVersion($nodeVersion);

    writeln('Using Node.js version: ' . $validatedNodeVersion);

    writeln('Checking if Node.js version is already installed...');

    $nodeIsInstalled = testLocally('source $HOME/.nvm/nvm.sh && nvm ls ' . safeEscapeShellArg($validatedNodeVersion) . ' | grep -q ' . safeEscapeShellArg($validatedNodeVersion));

    if ($nodeIsInstalled) {
        writeln('Node.js version is already installed. Using it...');
        runLocally('source $HOME/.nvm/nvm.sh && nvm use ' . safeEscapeShellArg($validatedNodeVersion));
    } else {
        writeln('Node.js version is not installed. Installing...');
        runLocally('source $HOME/.nvm/nvm.sh && nvm install ' . safeEscapeShellArg($validatedNodeVersion));
    }

    if (nodeSupportsCorepack($validatedNodeVersion)) {
        writeln('Enabling Corepack...');
        runLocally('source $HOME/.nvm/nvm.sh && corepack enable');
    }

    writeln('Installing dependencies...');
    runLocally('source $HOME/.nvm/nvm.sh && yarn install');

    writeln('Building assets...');
    runLocally('source $HOME/.nvm/nvm.sh && yarn build');
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
        // Validate directory path to prevent path traversal
        $validatedDir = validateDirectoryPath($dir);
        $localDir = $validatedDir . '/';
        $remoteDir = '{{release_path}}/' . $validatedDir;

        writeln('Uploading assets directory: ' . $validatedDir . '...');
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
    
    // Validate config name to prevent command injection
    $validatedConfig = validateConfigName($config);

    writeln('Restarting php-fpm service...');

    writeln('Restarting php-fpm config: ' . $validatedConfig . '...');
    run('sudo systemctl restart ' . safeEscapeShellArg($validatedConfig));
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
        // Validate config name to prevent command injection and path traversal
        $validatedConfig = validateConfigName($config);
        writeln('Restarting supervisor config: ' . $validatedConfig . '...');
        run('supervisorctl -c /etc/projects/supervisor/' . safeEscapeShellArg($validatedConfig) . ' restart all');
    }
});

before('deploy', 'lameco:stage_prompt');

before('deploy:symlink', 'lameco:build_assets');
after('lameco:build_assets', 'lameco:upload_assets');

after('deploy:cleanup', 'lameco:restart_php');
after('deploy:cleanup', 'lameco:restart_supervisor');

after('deploy:success', 'crontab:sync');

if (in_array(get('lameco_project_type'), ['symfony', 'kunstmaan'], true)) {
    before('deploy:symlink', 'database:migrate');
}
