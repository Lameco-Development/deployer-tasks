<?php

namespace Deployer;

require_once __DIR__ . '/functions.php';

// Deployer

set('deploy_path', '~');
set('keep_releases', 3);

$sharedDirs = get('shared_dirs');
set('shared_dirs', function () use ($sharedDirs) {
    $dirs = $sharedDirs;
    $projectType = get('lameco_project_type');
    if (in_array($projectType, ['kunstmaan', 'craftcms'], true)) {
        $dirs[] = '{{lameco_public_dir}}/uploads';
    }
    if ($projectType === 'craftcms') {
        $dirs[] = '{{lameco_public_dir}}/formie-uploads';
        $dirs[] = 'translations';
    }
    return array_unique($dirs);
});

$sharedFiles = get('shared_files');
set('shared_files', function () use ($sharedFiles) {
    $files = $sharedFiles;
    $projectType = get('lameco_project_type');
    if (in_array($projectType, ['symfony', 'kunstmaan'], true)) {
        $files[] = '.env';
    }
    return array_unique($files);
});

// Laméco

// Detect project type based on key files.
if (file_exists('bin/console') && file_exists('src/Kernel.php')) {
    if (composerHasPackage('kunstmaan/admin-bundle')) {
        $projectType = 'kunstmaan';
    } else {
        $projectType = 'symfony';
    }

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
set('lameco_dump_dir', $dumpDir);
set('lameco_public_dir', $publicDir);

// User-defined cron jobs array - can be overridden in deploy.php
// Format: [['schedule', 'command'], ['schedule', 'command'], ...]
// or ['schedule command', 'schedule command', ...]
set('lameco_cron_jobs', []);

// Backward compatibility: auto-detect legacy packages if no custom jobs defined
set('lameco_auto_detect_cron', true);

set('lameco_download_dirs', function () {
    $dirs = [];
    $projectType = get('lameco_project_type');
    if (in_array($projectType, ['kunstmaan', 'craftcms'], true)) {
        $dirs[] = '{{lameco_public_dir}}/uploads';
    }
    if ($projectType === 'craftcms') {
        $dirs[] = 'translations';
    }
    return array_unique($dirs);
});

set('lameco_upload_dirs', function () {
    $dirs = [];
    $projectType = get('lameco_project_type');
    if (in_array($projectType, ['kunstmaan', 'craftcms'], true)) {
        $dirs[] = '{{lameco_public_dir}}/uploads';
    }
    if ($projectType === 'craftcms') {
        $dirs[] = 'translations';
    }
    return array_unique($dirs);
});

set('lameco_assets_dirs', ['{{lameco_public_dir}}/dist']);
set('lameco_assets_files', []);

set('lameco_restart_supervisor', true);
set('lameco_supervisor_configs', ['{{http_user}}.conf']);

set('lameco_restart_php', true);
set('lameco_php_config', 'php-fpm-{{http_user}}.service');

// Dynamic crontab jobs configuration
set('crontab:jobs', function () {
    $jobs = [];
    $userJobs = get('lameco_cron_jobs');
    $autoDetect = get('lameco_auto_detect_cron');
    
    // Process user-defined cron jobs
    if (!empty($userJobs)) {
        foreach ($userJobs as $job) {
            if (is_array($job) && count($job) === 2) {
                // Format: ['* * * * *', 'php craft queue/run']
                $schedule = trim($job[0]);
                $command = trim($job[1]);
                $jobs[] = $schedule . ' cd {{current_path}} && ' . $command;
            } elseif (is_string($job)) {
                // Format: '* * * * * php craft queue/run' or already includes cd command
                $job = trim($job);
                if (strpos($job, 'cd {{current_path}}') === false && strpos($job, 'cd ') === false) {
                    // Extract schedule from command and add working directory
                    $parts = preg_split('/\s+/', $job, 6);
                    if (count($parts) >= 6) {
                        $schedule = implode(' ', array_slice($parts, 0, 5));
                        $command = $parts[5];
                        $jobs[] = $schedule . ' cd {{current_path}} && ' . $command;
                    } else {
                        // Fallback: assume the whole string is correct
                        $jobs[] = $job;
                    }
                } else {
                    // Job already includes working directory
                    $jobs[] = $job;
                }
            }
        }
    }
    
    // Backward compatibility: auto-detect packages if enabled and no user jobs
    if ($autoDetect && empty($userJobs)) {
        if (composerHasPackage('putyourlightson/craft-blitz')) {
            $jobs[] = '5 * * * * cd {{current_path}} && {{bin/php}} craft blitz/cache/refresh-expired';
        }

        if (composerHasPackage('verbb/formie')) {
            $jobs[] = '5 * * * * cd {{current_path}} && {{bin/php}} craft formie/gc/prune-data-retention-submissions';
        }
    }

    return $jobs;
});
