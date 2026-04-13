<?php

namespace Deployer;

use Deployer\Exception\GracefulShutdownException;

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/functions.php';

require 'contrib/crontab.php';

// Verify local branch matches deployment branch.
desc('Verify local branch matches deployment branch');
task('lameco:verify_deploy_branch', function (): void {
    $selectedHost = currentHost();
    if (! $selectedHost) {
        return;
    }

    $hostAlias = $selectedHost->getAlias();
    $hostName = $selectedHost->getHostname();
    $hostLabel = $hostAlias ?? $hostName ?? 'current host';
    $hostBranch = $selectedHost->get('branch');
    $stagingMatch = isStaging();
    if (empty($hostBranch)) {
        return;
    }

    $remoteRefs = trim(runLocally('git ls-remote --heads origin || true'));
    $remoteBranches = [];
    $releaseBranches = [];
    foreach (preg_split('/\r?\n/', $remoteRefs) as $line) {
        $parts = preg_split('/\s+/', trim($line));
        if (! isset($parts[1]) || ! str_starts_with($parts[1], 'refs/heads/')) {
            continue;
        }
        $branch = substr($parts[1], strlen('refs/heads/'));
        $remoteBranches[] = $branch;
        if (str_starts_with($branch, 'release/')) {
            $releaseBranches[] = $branch;
        }
    }

    if (! empty($remoteBranches) && ! in_array($hostBranch, $remoteBranches, true)) {
        $message = 'Configured branch "' . $hostBranch . '" for ' . $hostLabel . ' does not exist on origin.';
        error($message);
        throw new GracefulShutdownException($message);
    }

    if ($stagingMatch && ! empty($releaseBranches) && ! in_array($hostBranch, $releaseBranches, true)) {
        $message = 'Release branches exist on origin, but ' . $hostLabel .
            ' is not configured to deploy a release/* branch. Set the host branch to a release/* value.';
        error($message);
        throw new GracefulShutdownException($message);
    }

    $localBranch = trim(runLocally('git rev-parse --abbrev-ref HEAD'));
    if ($localBranch === 'HEAD') {
        $message = 'Local checkout is detached. Switch to a branch before deploying.';
        error($message);
        throw new GracefulShutdownException($message);
    }

    if ($localBranch !== $hostBranch) {
        $message = 'Local branch "' . $localBranch . '" does not match deployment branch "' . $hostBranch .
            '" for ' . $hostLabel . '.';
        error($message);
        throw new GracefulShutdownException($message);
    }
});

// Download remote database and import locally.
desc('Download remote database and import locally');
task('lameco:db_download', function (): void {
    within('{{deploy_path}}/shared', function (): void {
        writeln('Reading remote .env file...');
        $remoteEnvContent = run('cat .env');
        $remoteEnv = fetchEnv($remoteEnvContent);

        [$remoteDatabaseUser, $remoteDatabasePassword, $remoteDatabaseName] = extractDbCredentials($remoteEnv);

        if (! isset($remoteDatabaseUser, $remoteDatabasePassword, $remoteDatabaseName)) {
            error('Could not extract remote database credentials.');
            return;
        }

        $dumpFile = 'current_' . $remoteDatabaseName . '.sql.gz';
        set('dump_file', $dumpFile);

        $remotePath = '{{deploy_path}}/shared/{{dump_file}}';
        $localPath = '{{lameco_dump_dir}}/{{dump_file}}';
        $remotePathArg = escapeshellarg($dumpFile);

        writeln('Creating remote database dump...');
        $remoteUserArg = escapeshellarg($remoteDatabaseUser);
        $remotePassEnv = escapeshellarg($remoteDatabasePassword);
        $remoteDbArg = escapeshellarg($remoteDatabaseName);
        run('MYSQL_PWD=' . $remotePassEnv . ' mysqldump --quick --single-transaction -u ' . $remoteUserArg . ' ' . $remoteDbArg . ' | gzip > ' . $remotePathArg);

        writeln('Downloading database dump to local path: ' . $localPath . '...');
        download($remotePath, $localPath);

        writeln('Removing remote database dump...');
        run('rm ' . $remotePathArg);
    });

    $localPath = '{{lameco_dump_dir}}/{{dump_file}}';
    $localPathArg = escapeshellarg($localPath);

    writeln('Importing database from local dump: ' . $localPath . '...');

    writeln('Reading local .env file...');
    $localEnvContent = file_get_contents('.env');
    $localEnv = fetchEnv($localEnvContent);

    [$localDatabaseUser, $localDatabasePassword, $localDatabaseName] = extractDbCredentials($localEnv);

    if (! isset($localDatabaseUser, $localDatabasePassword, $localDatabaseName)) {
        error('Could not extract local database credentials.');
        return;
    }

    writeln('Creating local database if it does not exist...');
    $localUserArg = escapeshellarg((string) $localDatabaseUser);
    $localPassEnv = escapeshellarg((string) $localDatabasePassword);
    $localDbName = str_replace('`', '``', $localDatabaseName);
    $createSql = 'DROP DATABASE IF EXISTS `' . $localDbName . '`; CREATE DATABASE `' . $localDbName . '`;';
    runLocally('MYSQL_PWD=' . $localPassEnv . ' mysql -u ' . $localUserArg . ' -e ' . escapeshellarg($createSql));

    writeln('Importing database dump into local database...');
    runLocally('gunzip -c ' . $localPathArg . ' | MYSQL_PWD=' . $localPassEnv . ' mysql -u ' . $localUserArg . ' ' . escapeshellarg((string) $localDatabaseName));

    writeln('Removing local dump file...');
    runLocally('rm ' . $localPathArg);
});

// Download remote database credentials.
desc('Download remote database credentials');
task('lameco:db_credentials', function (): void {
    within('{{deploy_path}}/shared', function (): void {
        $envContent = run('cat .env');
        $env = fetchEnv($envContent);

        [$remoteDatabaseUser, $remoteDatabasePassword] = extractDbCredentials($env);

        if (! isset($remoteDatabaseUser, $remoteDatabasePassword)) {
            writeln('Could not extract remote database credentials.');
            return;
        }

        writeln('Remote database username: ' . $remoteDatabaseUser);
        writeln('Remote database password: ' . $remoteDatabasePassword);
    });
});

// Upload a local database dump to remote and import it.
desc('Upload local database dump to remote and import it');
task('lameco:db_upload', function (): void {
    $dumpDir = get('lameco_dump_dir');

    if (has('dump_file')) {
        $dumpFile = get('dump_file');
    } else {
        $dumpFiles = glob($dumpDir . '/current_*.sql.gz') ?: [];

        if (empty($dumpFiles)) {
            error('No dump file found in ' . $dumpDir . '. Please ensure a dump file exists or set dump_file explicitly.');
            return;
        }

        usort($dumpFiles, fn (string $a, string $b): int => (int) filemtime($b) - (int) filemtime($a));

        $dumpFile = basename($dumpFiles[0]);
    }

    $localPath = $dumpDir . '/' . $dumpFile;

    if (! file_exists($localPath)) {
        error('Local dump file not found: ' . $localPath);
        return;
    }

    within('{{deploy_path}}/shared', function () use ($dumpFile, $localPath): void {
        writeln('Reading remote .env file...');
        $remoteEnvContent = run('cat .env');
        $remoteEnv = fetchEnv($remoteEnvContent);

        [$remoteDatabaseUser, $remoteDatabasePassword, $remoteDatabaseName] = extractDbCredentials($remoteEnv);

        if (! isset($remoteDatabaseUser, $remoteDatabasePassword, $remoteDatabaseName)) {
            error('Could not extract remote database credentials.');
            return;
        }

        $remotePath = '{{deploy_path}}/shared/' . $dumpFile;
        $remotePathArg = escapeshellarg($dumpFile);

        writeln('Uploading database dump to remote: ' . $remotePath . '...');
        upload($localPath, $remotePath);

        writeln('Creating remote database...');
        $remoteUserArg = escapeshellarg($remoteDatabaseUser);
        $remotePassEnv = escapeshellarg($remoteDatabasePassword);
        $remoteDbName = str_replace('`', '``', $remoteDatabaseName);
        $createSql = 'DROP DATABASE IF EXISTS `' . $remoteDbName . '`; CREATE DATABASE `' . $remoteDbName . '`;';
        run('MYSQL_PWD=' . $remotePassEnv . ' mysql -u ' . $remoteUserArg . ' -e ' . escapeshellarg($createSql));

        writeln('Importing database dump into remote database...');
        $remoteDbArg = escapeshellarg($remoteDatabaseName);
        run('gunzip -c ' . $remotePathArg . ' | MYSQL_PWD=' . $remotePassEnv . ' mysql -u ' . $remoteUserArg . ' ' . $remoteDbArg);

        writeln('Removing remote dump file...');
        run('rm ' . $remotePathArg);
    });

    writeln('Removing local dump file...');
    runLocally('rm ' . escapeshellarg($localPath));
});

// Download directories from remote to local.
desc('Download directories from remote to local');
task('lameco:download', function (): void {
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
        download($remoteDir, $localDir, [
            'options' => ['--copy-links'],
        ]);
    }
});

// Upload directories from local to remote.
desc('Upload directories from local to remote');
task('lameco:upload', function (): void {
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

// Sync database and files from one host to another via SSH streaming.
desc('Sync database and files from one host to another');
task('lameco:sync', function (): void {
    $deployer = Deployer::get();

    $hostAliases = array_keys($deployer->hosts->all());

    if (count($hostAliases) < 2) {
        error('At least two hosts must be configured to use lameco:sync.');
        return;
    }

    $syncScope = (string) askChoice('Select what to sync:', [
        'Database and files',
        'Database only',
        'Files only',
    ], 0);

    $syncDb = $syncScope !== 'Files only';
    $syncFiles = $syncScope !== 'Database only';

    $source = (string) askChoice('Select source host (data will be copied FROM this host):', $hostAliases, 0);
    $destination = (string) askChoice('Select destination host (data will be written TO this host):', $hostAliases, 1);

    if ($source === $destination) {
        error('Source and destination must be different hosts.');
        return;
    }

    $sourceHost = $deployer->hosts->get($source);
    $destHost = $deployer->hosts->get($destination);

    if ($syncDb && $syncFiles) {
        $scopeWarning = 'The database and uploaded files on the destination';
    } elseif ($syncDb) {
        $scopeWarning = 'The database on the destination';
    } else {
        $scopeWarning = 'The uploaded files on the destination';
    }

    writeln('');
    writeln('╔══════════════════════════════════════════════════════════════╗');
    writeln('║                                                              ║');
    writeln('║   ⚠  THIS WILL OVERWRITE DATA ON THE DESTINATION HOST       ║');
    writeln('║                                                              ║');
    writeln('║   Source:       ' . str_pad($source, 45) . '  ║');
    writeln('║   Destination:  ' . str_pad($destination, 45) . '  ║');
    writeln('║   Scope:        ' . str_pad($syncScope, 45) . '  ║');
    writeln('║                                                              ║');
    writeln('║   ' . str_pad($scopeWarning, 59) . '║');
    writeln('║   will be permanently overwritten. This cannot be undone.   ║');
    writeln('║                                                              ║');
    writeln('╚══════════════════════════════════════════════════════════════╝');
    writeln('');

    if (! askConfirmation('Are you absolutely sure you want to proceed?', false)) {
        writeln('Sync cancelled.');
        return;
    }

    $sourceSsh = buildSshCommand($sourceHost);
    $destSsh = buildSshCommand($destHost);

    // Stream database from source to destination via local pipe.
    if ($syncDb) {
        writeln('');
        writeln('→ Reading database credentials...');

        $sourceDbUser = null;
        $sourceDbPassword = null;
        $sourceDbName = null;

        on($sourceHost, function () use (&$sourceDbUser, &$sourceDbPassword, &$sourceDbName): void {
            within('{{deploy_path}}/shared', function () use (&$sourceDbUser, &$sourceDbPassword, &$sourceDbName): void {
                $envContent = run('cat .env');
                $env = fetchEnv($envContent);
                [$sourceDbUser, $sourceDbPassword, $sourceDbName] = extractDbCredentials($env);
            });
        });

        if (! isset($sourceDbUser, $sourceDbPassword, $sourceDbName)) {
            error('Could not extract database credentials from source host.');
            return;
        }

        $destDbUser = null;
        $destDbPassword = null;
        $destDbName = null;

        on($destHost, function () use (&$destDbUser, &$destDbPassword, &$destDbName): void {
            within('{{deploy_path}}/shared', function () use (&$destDbUser, &$destDbPassword, &$destDbName): void {
                $envContent = run('cat .env');
                $env = fetchEnv($envContent);
                [$destDbUser, $destDbPassword, $destDbName] = extractDbCredentials($env);
            });
        });

        if (! isset($destDbUser, $destDbPassword, $destDbName)) {
            error('Could not extract database credentials from destination host.');
            return;
        }

        writeln('→ Preparing destination database...');
        $destDbNameEscaped = str_replace('`', '``', $destDbName);
        $createSql = 'DROP DATABASE IF EXISTS `' . $destDbNameEscaped . '`; CREATE DATABASE `' . $destDbNameEscaped . '`;';
        $prepCmd = 'MYSQL_PWD=' . escapeshellarg((string) $destDbPassword)
            . ' mysql -u ' . escapeshellarg((string) $destDbUser)
            . ' -e ' . escapeshellarg($createSql);
        runLocally($destSsh . ' ' . escapeshellarg($prepCmd));

        writeln('→ Streaming database from ' . $source . ' to ' . $destination . '...');
        $dumpCmd = 'MYSQL_PWD=' . escapeshellarg((string) $sourceDbPassword)
            . ' mysqldump --quick --single-transaction -u ' . escapeshellarg((string) $sourceDbUser)
            . ' ' . escapeshellarg((string) $sourceDbName) . ' | gzip';
        $importCmd = 'gunzip | MYSQL_PWD=' . escapeshellarg((string) $destDbPassword)
            . ' mysql -u ' . escapeshellarg((string) $destDbUser)
            . ' ' . escapeshellarg((string) $destDbName);
        runLocally(
            $sourceSsh . ' ' . escapeshellarg($dumpCmd) . ' | ' . $destSsh . ' ' . escapeshellarg($importCmd),
            [
                'timeout' => null,
            ],
        );
    }

    // Stream files from source to destination via tar pipe.
    if ($syncFiles) {
        $downloadDirs = [];
        $sourceDeployPath = '';
        $destDeployPath = '';

        on($sourceHost, function () use (&$downloadDirs, &$sourceDeployPath): void {
            $downloadDirs = array_map(fn (string $dir): string => parse($dir), get('lameco_download_dirs'));
            $sourceDeployPath = run('echo {{deploy_path}}');
        });

        on($destHost, function () use (&$destDeployPath): void {
            $destDeployPath = run('echo {{deploy_path}}');
        });

        if (empty($downloadDirs)) {
            writeln('No directories configured for sync.');
        } else {
            foreach ($downloadDirs as $dir) {
                writeln('');
                writeln('→ Streaming directory ' . $dir . ' from ' . $source . ' to ' . $destination . '...');

                $mkdirCmd = 'mkdir -p ' . escapeshellarg($destDeployPath . '/shared/' . $dir);
                runLocally($destSsh . ' ' . escapeshellarg($mkdirCmd));

                $tarSource = 'tar czf - -C ' . escapeshellarg($sourceDeployPath . '/shared') . ' ' . escapeshellarg($dir);
                $tarDest = 'tar xzf - -C ' . escapeshellarg($destDeployPath . '/shared');
                runLocally(
                    $sourceSsh . ' ' . escapeshellarg($tarSource) . ' | ' . $destSsh . ' ' . escapeshellarg($tarDest),
                    [
                        'timeout' => null,
                    ],
                );
            }
        }
    }

    // Restart services on destination.
    writeln('');
    writeln('→ Restarting PHP on ' . $destination . '...');
    on($destHost, function (): void {
        invoke('lameco:restart_php');
    });

    writeln('→ Restarting Supervisor on ' . $destination . '...');
    on($destHost, function (): void {
        invoke('lameco:restart_supervisor');
    });

    writeln('');
    writeln('✔ Sync from ' . $source . ' to ' . $destination . ' completed.');
})->once();

// Build local assets.
desc('Build local assets');
task('lameco:build_assets', function (): void {
    writeln('Loading Node.js version from .nvmrc...');

    if (! file_exists('.nvmrc')) {
        throw new \RuntimeException('.nvmrc file not found.');
    }

    $nodeVersion = trim(file_get_contents('.nvmrc'));
    if ($nodeVersion === '') {
        throw new \RuntimeException('.nvmrc file is empty.');
    }

    writeln('Using Node.js version: ' . $nodeVersion);

    $nodeVersionArg = escapeshellarg($nodeVersion);
    $nvmInit = 'export NVM_DIR="${NVM_DIR:-$HOME/.nvm}" && [ -s "$NVM_DIR/nvm.sh" ] && . "$NVM_DIR/nvm.sh"';
    $runWithNvm = function (string $command) use ($nvmInit, $nodeVersionArg): string {
        $fullCommand = $nvmInit . ' && nvm use ' . $nodeVersionArg . ' >/dev/null && ' . $command;
        return 'bash -lc ' . escapeshellarg($fullCommand);
    };

    writeln('Checking if Node.js version is already installed...');

    $nodeIsInstalled = testLocally('bash -lc ' . escapeshellarg(
        $nvmInit . ' && [ "$(nvm version ' . $nodeVersionArg . ')" != "N/A" ]'
    ));

    if ($nodeIsInstalled) {
        writeln('Node.js version is already installed.');
    } else {
        writeln('Node.js version is not installed. Installing...');
        runLocally('bash -lc ' . escapeshellarg($nvmInit . ' && nvm install ' . $nodeVersionArg));
    }

    if (nodeSupportsCorepack($nodeVersion)) {
        writeln('Enabling Corepack...');
        runLocally($runWithNvm('corepack enable'));
    }

    writeln('Installing dependencies...');
    runLocally($runWithNvm('yarn install'));

    writeln('Building assets...');
    runLocally($runWithNvm('yarn build ' . get('lameco_assets_build_flags')));
})->once();

// Upload built assets to remote.
desc('Upload built assets to remote');
task('lameco:upload_assets', function (): void {
    $assetsDirs = get('lameco_assets_dirs');
    $assetsFiles = get('lameco_assets_files');

    if (empty($assetsDirs) && empty($assetsFiles)) {
        writeln('No assets directories or files configured.');
        return;
    }

    writeln('Uploading built assets from local to remote...');

    // Upload directories
    if (! empty($assetsDirs)) {
        foreach ($assetsDirs as $dir) {
            $localDir = $dir . '/';
            $remoteDir = '{{release_path}}/' . $dir;

            writeln('Uploading assets directory: ' . $dir . '...');
            upload($localDir, $remoteDir);
            run('chmod -R 755 ' . $remoteDir);
        }
    }

    // Upload individual files
    if (! empty($assetsFiles)) {
        foreach ($assetsFiles as $file) {
            $localFile = $file;
            $remoteFile = '{{release_path}}/' . $file;

            writeln('Uploading assets file: ' . $file . '...');
            upload($localFile, $remoteFile);
        }
    }
});

// Restart php-fpm service.
desc('Restart php-fpm service');
task('lameco:restart_php', function (): void {
    if (! get('lameco_restart_php')) {
        writeln('php-fpm is not enabled for this project.');
        return;
    }

    $config = get('lameco_php_config');

    if (! $config) {
        writeln('No php-fpm config configured.');
        return;
    }

    writeln('Restarting php-fpm service...');

    writeln('Restarting php-fpm config: ' . $config . '...');
    run('sudo systemctl restart ' . escapeshellarg($config));
});

// Restart supervisor.
desc('Restart supervisor');
task('lameco:restart_supervisor', function (): void {
    if (! get('lameco_restart_supervisor')) {
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
        $configPath = '/etc/projects/supervisor/' . $config;
        run('supervisorctl -c ' . escapeshellarg($configPath) . ' restart all');
    }
});

// Update .htpasswd for staging environments.
desc('Update .htpasswd for staging environments');
task('lameco:update_htpasswd', function (): void {
    $selectedHost = currentHost();
    if (! $selectedHost) {
        return;
    }

    // Check if this is a staging environment
    if (! isStaging()) {
        writeln('Skipping .htpasswd update - not a staging environment.');
        return;
    }

    $httpUser = get('http_user');

    if (! $httpUser) {
        error('http_user variable is not set.');
        return;
    }

    writeln('Updating .htpasswd for staging environment...');

    $htpasswdPath = '/projects/' . $httpUser . '/.local/nginx/.htpasswd';
    $username = 'lameco';

    // Generate bcrypt hash of the http_user (password)
    writeln('Generating bcrypt hash for password...');
    $hashedPassword = run('mkpasswd -m bcrypt ' . escapeshellarg($httpUser));

    // Ensure the directory exists
    run('mkdir -p ' . escapeshellarg('/projects/' . $httpUser . '/.local/nginx'));

    // Create or update the .htpasswd file
    $htpasswdEntry = $username . ':' . trim($hashedPassword);

    // Check if the file exists and if the entry is already correct
    $fileExists = test('[ -f "' . $htpasswdPath . '" ]');

    // Write the .htpasswd entry
    run('echo ' . escapeshellarg($htpasswdEntry) . ' > ' . escapeshellarg($htpasswdPath));
    writeln('.htpasswd updated successfully at: ' . $htpasswdPath);
});

// Deactivate a staging environment.
desc('Deactivate staging environment');
task('lameco:deactivate', function (): void {
    if (! isStaging()) {
        throw new GracefulShutdownException('This task can only be run on staging environments.');
    }

    $httpUser = get('http_user');
    $publicDir = get('lameco_public_dir');

    // Clear warning
    writeln('');
    writeln('╔══════════════════════════════════════════════════════════════╗');
    writeln('║                                                              ║');
    writeln('║   ⚠  STAGING OMGEVING WORDT GEDEACTIVEERD                   ║');
    writeln('║                                                              ║');
    writeln('║   Host:  ' . str_pad(currentHost()->getAlias() ?? currentHost()->getHostname(), 48) . '  ║');
    writeln('║   User:  ' . str_pad((string) $httpUser, 48) . '  ║');
    writeln('║                                                              ║');
    writeln('║   Dit zal de staging omgeving volledig uitschakelen.         ║');
    writeln('║   De site wordt vervangen door een placeholder pagina.      ║');
    writeln('║                                                              ║');
    writeln('╚══════════════════════════════════════════════════════════════╝');
    writeln('');

    if (! askConfirmation('Weet je zeker dat je wilt doorgaan?', false)) {
        writeln('Geannuleerd.');
        return;
    }

    $wipeDatabase = askConfirmation('Database wissen?', true);
    $wipeShared = askConfirmation('Shared directories wissen? (.env wordt altijd behouden)', false);

    writeln('');
    writeln('Samenvatting:');
    writeln('  - Cronjobs verwijderen:       ja');
    writeln('  - Supervisor stoppen:          ja');
    writeln('  - Database wissen:            ' . ($wipeDatabase ? 'ja' : 'nee'));
    writeln('  - Shared directories wissen:  ' . ($wipeShared ? 'ja' : 'nee'));
    writeln('  - Placeholder pagina plaatsen: ja');
    writeln('  - PHP-FPM herstarten:          ja');
    writeln('');

    if (! askConfirmation('Bevestig deactivatie', false)) {
        writeln('Geannuleerd.');
        return;
    }

    // 1. Remove cronjobs
    writeln('');
    writeln('→ Cronjobs verwijderen...');
    run('crontab -r || true');
    writeln('  Cronjobs verwijderd.');

    // 2. Stop supervisor
    writeln('');
    writeln('→ Supervisor stoppen...');
    $supervisorConfigs = get('lameco_supervisor_configs');
    if (! empty($supervisorConfigs)) {
        foreach ($supervisorConfigs as $config) {
            $configPath = '/etc/projects/supervisor/' . $config;
            run('supervisorctl -c ' . escapeshellarg($configPath) . ' stop all || true');
            writeln('  Gestopt: ' . $config);
        }
    } else {
        writeln('  Geen supervisor configs gevonden.');
    }

    // 3. Wipe database
    if ($wipeDatabase) {
        writeln('');
        writeln('→ Database wissen...');
        within('{{deploy_path}}/shared', function (): void {
            if (! test('[ -f .env ]')) {
                writeln('  Geen .env gevonden, database overgeslagen.');
                return;
            }
            $envContent = run('cat .env');
            $env = fetchEnv($envContent);
            [$dbUser, $dbPassword, $dbName] = extractDbCredentials($env);

            if (! isset($dbUser, $dbPassword, $dbName)) {
                writeln('  Kan database credentials niet uitlezen, overgeslagen.');
                return;
            }

            $userArg = escapeshellarg($dbUser);
            $passEnv = escapeshellarg($dbPassword);
            $dbArg = escapeshellarg($dbName);

            // Drop all tables but keep the database itself
            $dropCmd = 'MYSQL_PWD=' . $passEnv . ' mysqldump --no-data -u ' . $userArg . ' ' . $dbArg
                . ' | grep "^DROP"'
                . ' | (echo "SET FOREIGN_KEY_CHECKS=0;"; cat; echo "SET FOREIGN_KEY_CHECKS=1;")'
                . ' | MYSQL_PWD=' . $passEnv . ' mysql -u ' . $userArg . ' ' . $dbArg;
            run($dropCmd);
            writeln('  Alle tabellen in "' . $dbName . '" verwijderd.');
        });
    }

    // 4. Wipe shared directory contents (always keep .env, keep directories themselves)
    if ($wipeShared) {
        writeln('');
        writeln('→ Shared directories legen (.env wordt behouden)...');
        $sharedPath = '{{deploy_path}}/shared';

        // Remove everything except .env (Deployer recreates shared dirs on next deploy)
        run('find ' . $sharedPath . ' -mindepth 1 ! -name .env -delete || true');
        writeln('  Shared directories geleegd.');
    }

    // 5. Replace site with placeholder page
    writeln('');
    writeln('→ Placeholder pagina plaatsen...');
    // Remove Deployer directories (current, releases, .dep)
    run('rm -rf {{deploy_path}}/current');
    run('rm -rf {{deploy_path}}/releases');
    run('rm -rf {{deploy_path}}/.dep');

    // Check for old Capistrano directories
    $capistranoItems = ['repo', 'revisions.log'];
    $foundCapistrano = [];
    foreach ($capistranoItems as $item) {
        if (test('[ -e {{deploy_path}}/' . $item . ' ]')) {
            $foundCapistrano[] = $item;
        }
    }

    if (! empty($foundCapistrano)) {
        writeln('');
        writeln('  Oude Capistrano bestanden gevonden:');
        foreach ($foundCapistrano as $item) {
            writeln('    - ' . $item);
        }

        if (askConfirmation('Capistrano bestanden verwijderen?', true)) {
            foreach ($foundCapistrano as $item) {
                run('rm -rf {{deploy_path}}/' . $item);
            }
            writeln('  Capistrano bestanden verwijderd.');
        }
    }

    // Create a real current directory (not a symlink) with placeholder
    $placeholderPath = '{{deploy_path}}/current/' . $publicDir;
    run('mkdir -p ' . $placeholderPath);

    $placeholderHtml = <<<'HTML'
        <!DOCTYPE html>
        <html lang="nl">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Staging niet actief</title>
            <style>
                * { margin: 0; padding: 0; box-sizing: border-box; }
                body {
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    background: #f5f5f5;
                    color: #333;
                }
                .container {
                    text-align: center;
                    padding: 2rem;
                }
                h1 {
                    font-size: 1.5rem;
                    font-weight: 600;
                    margin-bottom: 0.5rem;
                }
                p {
                    color: #666;
                    font-size: 1rem;
                }
            </style>
        </head>
        <body>
            <div class="container">
                <h1>Staging omgeving niet actief</h1>
                <p>Dit is een Lameco staging omgeving die niet in gebruik is.</p>
            </div>
        </body>
        </html>
        HTML;

    $encodedHtml = base64_encode($placeholderHtml);
    run('echo ' . escapeshellarg($encodedHtml) . ' | base64 -d > ' . $placeholderPath . '/index.html');
    writeln('  Placeholder pagina geplaatst.');

    // 6. Restart PHP-FPM
    writeln('');
    writeln('→ PHP-FPM herstarten...');
    $phpConfig = get('lameco_php_config');
    if ($phpConfig) {
        run('sudo systemctl restart ' . escapeshellarg($phpConfig));
        writeln('  PHP-FPM herstart: ' . $phpConfig);
    } else {
        writeln('  Geen PHP-FPM config gevonden.');
    }

    writeln('');
    writeln('✔ Staging omgeving is gedeactiveerd.');
    writeln('  Voer een deploy uit om de omgeving weer te activeren.');
});

before('deploy', 'lameco:verify_deploy_branch');

before('deploy:symlink', 'lameco:build_assets');
after('lameco:build_assets', 'lameco:upload_assets');

after('deploy:cleanup', 'lameco:restart_php');
after('deploy:cleanup', 'lameco:restart_supervisor');

after('deploy:success', 'crontab:sync');
after('deploy:success', 'lameco:update_htpasswd');

if (in_array(get('lameco_project_type'), ['symfony', 'kunstmaan'], true)) {
    before('deploy:symlink', 'database:migrate');
}
