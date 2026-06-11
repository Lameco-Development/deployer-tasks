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
 * Build an SSH command string from a Deployer Host object.
 *
 * @param \Deployer\Host\Host $host The Deployer host to build the SSH command for.
 * @return string The SSH command prefix (e.g. "ssh -p 22 -i key user@host").
 */
function buildSshCommand(\Deployer\Host\Host $host): string
{
    $options = $host->connectionOptionsString();
    $connection = escapeshellarg($host->connectionString());

    return 'ssh' . ($options !== '' ? ' ' . $options : '') . ' ' . $connection;
}

/**
 * Wrap a shell command for execution on a sync endpoint.
 *
 * Every wrapped command is executed locally via runLocally(). A remote endpoint
 * is reached over SSH; the local endpoint (null host) runs the command directly.
 *
 * @param \Deployer\Host\Host|null $host The endpoint host, or null for local.
 * @param string $command The shell command to wrap.
 * @return string The command to pass to runLocally().
 */
function wrapEndpointCommand(?\Deployer\Host\Host $host, string $command): string
{
    if (! $host instanceof \Deployer\Host\Host) {
        return $command;
    }

    return buildSshCommand($host) . ' ' . escapeshellarg($command);
}

/**
 * Read database credentials for a sync endpoint.
 *
 * Remote endpoints are read from {{deploy_path}}/shared/.env over SSH; the local
 * endpoint (null host) is read from the project-root .env file.
 *
 * @param \Deployer\Host\Host|null $host The endpoint host, or null for local.
 * @return array{0: string|null, 1: string|null, 2: string|null} [user, password, name].
 */
function readEndpointDbCredentials(?\Deployer\Host\Host $host): array
{
    if (! $host instanceof \Deployer\Host\Host) {
        if (! file_exists('.env')) {
            return [null, null, null];
        }

        $env = fetchEnv((string) file_get_contents('.env'));

        return extractDbCredentials($env);
    }

    $credentials = [null, null, null];

    on($host, function () use (&$credentials): void {
        within('{{deploy_path}}/shared', function () use (&$credentials): void {
            $env = fetchEnv(run('cat .env'));
            $credentials = extractDbCredentials($env);
        });
    });

    return $credentials;
}

/**
 * Resolve the shared base path for a sync endpoint.
 *
 * This is the directory that holds the synced subdirectories: {{deploy_path}}/shared
 * for a remote endpoint, or the local project root for the local endpoint.
 *
 * @param \Deployer\Host\Host|null $host The endpoint host, or null for local.
 * @return string The absolute shared base path.
 */
function getEndpointSharedPath(?\Deployer\Host\Host $host): string
{
    if (! $host instanceof \Deployer\Host\Host) {
        return (string) getcwd();
    }

    $sharedPath = '';

    on($host, function () use (&$sharedPath): void {
        $sharedPath = run('echo {{deploy_path}}/shared');
    });

    return $sharedPath;
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

/**
 * Generate a deterministic minute value for cron jobs in 5-minute increments.
 * Tracks used values to avoid overlaps within the same deployment.
 * Returns values like 0, 5, 10, 15, 20, 25, 30, 35, 40, 45, 50, 55.
 * When all 12 slots are used, it cycles back to the beginning.
 *
 * @return int A minute value in 5-minute increments (0-55).
 */
function getCronMinute(): int
{
    static $usedSlots = [];
    static $nextSlot = null;

    // Initialize on first call
    if ($nextSlot === null) {
        // Use http_user as the seed since it's unique per project
        $seed = get('http_user', false) ?: get('deploy_path');

        // Generate a deterministic starting slot for this project
        $nextSlot = abs(crc32((string) $seed)) % 12;
    }

    // Find the next available slot
    $slot = $nextSlot;
    $attempts = 0;

    // If we've cycled through all 12 slots, reset
    if (count($usedSlots) >= 12) {
        $usedSlots = [];
    }

    // Find next unused slot (or wrap around if all used)
    while (in_array($slot, $usedSlots, true) && $attempts < 12) {
        $slot = ($slot + 1) % 12;
        $attempts++;
    }

    // Mark this slot as used
    $usedSlots[] = $slot;

    // Update next slot for next call
    $nextSlot = ($slot + 1) % 12;

    // Convert slot to minute (multiply by 5)
    return $slot * 5;
}
