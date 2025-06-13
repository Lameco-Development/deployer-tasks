<?php

namespace Deployer;

set('deploy_path', '~');
set('keep_releases', 3);

add('shared_dirs', function () {
    $dirs = [];
    $projectType = get('lameco_project_type');
    if (in_array($projectType, ['kunstmaan', 'craftcms'], true)) {
        $dirs[] = '{{lameco_public_dir}}/uploads';
    }
    if ($projectType === 'craftcms') {
        $dirs[] = '{{lameco_public_dir}}/formie-uploads';
        $dirs[] = 'translations';
    }
    return $dirs;
});

add('shared_files', function () {
    $files = [];
    $projectType = get('lameco_project_type');
    if (in_array($projectType, ['symfony', 'kunstmaan'], true)) {
        $files[] = '.env';
    }
    return $files;
});

set('lameco_download_dirs', function () {
    $dirs = [];
    $projectType = get('lameco_project_type');
    if (in_array($projectType, ['kunstmaan', 'craftcms'], true)) {
        $dirs[] = '{{lameco_public_dir}}/uploads';
    }
    if ($projectType === 'craftcms') {
        $dirs[] = 'translations';
    }
    return $dirs;
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
    return $dirs;
});

set('lameco_assets_dirs', ['{{lameco_public_dir}}/dist']);

set('lameco_restart_supervisor', true);
set('lameco_supervisor_configs', ['{{http_user}}.conf']);

set('lameco_restart_php', true);
set('lameco_php_config', 'php-fpm-{{http_user}}.service');

set('crontab:jobs', function () {
    $jobs = [];

    if (composerHasPackage('putyourlightson/craft-blitz')) {
        $jobs[] = '5 * * * * cd {{current_path}} && {{bin/php}} craft blitz/cache/refresh-expired';
    }

    if (composerHasPackage('verbb/formie')) {
        $jobs[] = '5 * * * * cd {{current_path}} && {{bin/php}} craft formie/gc/prune-data-retention-submissions';
    }

    return $jobs;
});
