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
    foreach (preg_split('/\r?\n/', $envContent) as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, ';')) {
            continue;
        }

        if (str_starts_with($line, 'export ')) {
            $line = trim(substr($line, strlen('export ')));
        }

        $pos = strpos($line, '=');
        if ($pos === false) {
            continue;
        }

        $key = trim(substr($line, 0, $pos));
        if ($key === '') {
            continue;
        }

        $value = trim(substr($line, $pos + 1));
        if ($value === '') {
            $env[$key] = '';
            continue;
        }

        $quote = $value[0];
        if (($quote === '"' || $quote === "'") && str_ends_with($value, $quote)) {
            $value = substr($value, 1, -1);
            if ($quote === '"') {
                $value = str_replace(
                    ['\\n', '\\r', '\\t', '\\\\', '\\"', '\\$'],
                    ["\n", "\r", "\t", '\\', '"', '$'],
                    $value
                );
            }
        } else {
            $value = preg_replace('/\s[;#].*$/', '', $value);
            $value = trim((string) $value);
        }

        $env[$key] = $value;
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
        $url = (string) $env['DATABASE_URL'];
        $parts = parse_url($url);
        if (is_array($parts) && (! isset($parts['scheme']) || in_array($parts['scheme'], ['mysql', 'mariadb'], true))) {
            $user = isset($parts['user']) ? rawurldecode($parts['user']) : null;
            $pass = isset($parts['pass']) ? rawurldecode($parts['pass']) : null;
            $name = isset($parts['path']) ? ltrim($parts['path'], '/') : null;
            if ($name !== null && $name !== '') {
                $query = [];
                if (isset($parts['query'])) {
                    parse_str($parts['query'], $query);
                }
                if (isset($query['dbname']) && $query['dbname'] !== '') {
                    $name = $query['dbname'];
                }
                return [$user, $pass, $name];
            }
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

/**
 * Determine if the current host is a staging environment.
 *
 * @return bool True if the current host is a staging environment, false otherwise.
 */
function isStaging(): bool
{
    $selectedHost = currentHost();
    if (! $selectedHost) {
        return false;
    }

    // Check if this is a staging environment
    $hostAlias = (string) ($selectedHost->getAlias() ?? '');
    $hostName = (string) ($selectedHost->getHostname() ?? '');
    $stage = (string) ($selectedHost->getLabels()['stage'] ?? '');

    return $stage === 'staging' || str_contains($hostAlias, 'staging') || str_contains($hostName, 'staging');
}
