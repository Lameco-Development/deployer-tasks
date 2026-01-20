<?php

namespace Deployer;

require_once __DIR__ . '/functions.php';

// Deployer

set('deploy_path', '~');

set('lameco_keep_releases_staging', 1);
set('lameco_keep_releases_default', 3);
set('keep_releases', fn () => isStaging()
    ? get('lameco_keep_releases_staging')
    : get('lameco_keep_releases_default'));

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

set('crontab:jobs', function () {
    $jobs = [];

    if (composerHasPackage('putyourlightson/craft-blitz')) {
        $jobs[] = getCronMinute() . ' * * * * cd {{current_path}} && {{bin/php}} craft blitz/cache/refresh-expired';
    }

    if (composerHasPackage('verbb/formie')) {
        $jobs[] = getCronMinute() . ' * * * * cd {{current_path}} && {{bin/php}} craft formie/gc/prune-data-retention-submissions';
    }

    return $jobs;
});

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
set('lameco_assets_build_flags', '');

set('lameco_restart_supervisor', true);
set('lameco_supervisor_configs', ['{{http_user}}.conf']);

set('lameco_restart_php', true);
set('lameco_php_config', 'php-fpm-{{http_user}}.service');
