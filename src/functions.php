<?php

namespace Deployer;

/**
 * Parse .env content into an associative array.
 *
 * @param string $envContent The raw content of the .env file.
 * @return array Associative array of environment variables.
 */
function fetchEnv(string $envContent): array
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
 * Extract database credentials from an environment array.
 * Supports DATABASE_URL (Symfony), CRAFT_DB_* (Craft CMS), and Laravel style.
 *
 * @param array $env Associative array of environment variables.
 * @return array Array with [user, password, database] or [null, null, null] if not found.
 */
function extractDbCredentials(array $env): array
{
    if (! empty($env['DATABASE_URL'])) {
        if (preg_match('|mysql://([^:]+):([^@]+)@[^/]+/([^?]+)|', (string) $env['DATABASE_URL'], $dbMatch)) {
            return [$dbMatch[1], $dbMatch[2], $dbMatch[3]];
        }
    } elseif (! empty($env['CRAFT_DB_DATABASE'])) {
        return [
            $env['CRAFT_DB_USER'] ?? null,
            $env['CRAFT_DB_PASSWORD'] ?? null,
            $env['CRAFT_DB_DATABASE'],
        ];
    } elseif (! empty($env['DB_DATABASE'])) { // Fallback for Laravel style
        return [
            $env['DB_USER'] ?? ($env['DB_USERNAME'] ?? null),
            $env['DB_PASSWORD'] ?? null,
            $env['DB_DATABASE'],
        ];
    }
    return [null, null, null];
}

/**
 * Determine if the given Node.js version supports Corepack.
 *
 * @param string $versionString Node.js version string (e.g. "v16.13.0").
 * @return bool True if Corepack is supported, false otherwise.
 */
function nodeSupportsCorepack(string $versionString): bool
{
    if (preg_match('/v(\d+)\.(\d+)\.(\d+)/', $versionString, $m)) {
        $major = (int) $m[1];
        $minor = (int) $m[2];
        // Corepack from 14.19+, 16.9+, or >16
        return ($major === 14 && $minor >= 19) ||
            ($major === 16 && $minor >= 9) ||
            ($major > 16);
    }
    return false;
}

/**
 * Check if the given Composer package is present in composer.json (require or require-dev).
 *
 * @param string $package Composer package name.
 * @return bool True if the package is required, false otherwise.
 */
function composerHasPackage(string $package): bool
{
    $composerJsonPath = 'composer.json';
    if (! file_exists($composerJsonPath)) {
        return false;
    }
    $composer = json_decode(file_get_contents($composerJsonPath), true, 512, JSON_THROW_ON_ERROR);
    $require = array_merge(
        $composer['require'] ?? [],
        $composer['require-dev'] ?? []
    );
    return array_key_exists($package, $require);
}
