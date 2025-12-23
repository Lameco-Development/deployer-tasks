<?php

declare(strict_types=1);

use PhpCsFixer\Fixer\Import\NoUnusedImportsFixer;
use Symplify\EasyCodingStandard\Config\ECSConfig;

return ECSConfig::configure()
    ->withPaths([
        __DIR__ . '/src',
    ])

    ->withRules([
        NoUnusedImportsFixer::class,
    ])
    ->withPreparedSets(
        psr12: true,
        common: true,
        cleanCode: true,
    )
    ->withPhpCsFixerSets(
        php84Migration: true,
    );
