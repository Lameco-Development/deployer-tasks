<?php

namespace Deployer;

/**
 * Security utility functions for safe shell command execution.
 */

/**
 * Safely escape shell arguments to prevent command injection.
 *
 * @param string $arg The argument to escape
 * @return string Safely escaped argument
 */
function safeEscapeShellArg(string $arg): string
{
    return escapeshellarg($arg);
}

/**
 * Safely escape shell command components.
 *
 * @param string $cmd The command component to escape
 * @return string Safely escaped command component
 */
function safeEscapeShellCmd(string $cmd): string
{
    return escapeshellcmd($cmd);
}

/**
 * Validate and sanitize configuration names to prevent path traversal.
 *
 * @param string $name The configuration name to validate
 * @return string Sanitized configuration name
 * @throws \InvalidArgumentException If the name is invalid
 */
function validateConfigName(string $name): string
{
    // Allow only alphanumeric characters, hyphens, underscores, and dots
    if (!preg_match('/^[a-zA-Z0-9._-]+$/', $name)) {
        throw new \InvalidArgumentException('Invalid configuration name: ' . $name);
    }
    
    // Prevent path traversal
    if (str_contains($name, '..') || str_contains($name, '/') || str_contains($name, '\\')) {
        throw new \InvalidArgumentException('Configuration name contains invalid path characters: ' . $name);
    }
    
    return $name;
}

/**
 * Validate Node.js version string.
 *
 * @param string $version The version string to validate
 * @return string Validated version string
 * @throws \InvalidArgumentException If the version is invalid
 */
function validateNodeVersion(string $version): string
{
    // Allow version patterns like "v16.13.0", "16.13.0", "lts", "stable"
    if (!preg_match('/^v?\d+\.\d+\.\d+$|^(lts|stable)$/', $version)) {
        throw new \InvalidArgumentException('Invalid Node.js version: ' . $version);
    }
    
    return $version;
}

/**
 * Validate stage name to prevent command injection.
 *
 * @param string $stage The stage name to validate
 * @return string Validated stage name
 * @throws \InvalidArgumentException If the stage is invalid
 */
function validateStage(string $stage): string
{
    // Allow only alphanumeric characters, hyphens, and underscores
    if (!preg_match('/^[a-zA-Z0-9_-]+$/', $stage)) {
        throw new \InvalidArgumentException('Invalid stage name: ' . $stage);
    }
    
    return $stage;
}

/**
 * Create a secure temporary MySQL configuration file.
 *
 * @param string $user Database username
 * @param string $password Database password
 * @return string Path to temporary configuration file
 */
function createSecureMysqlConfig(string $user, string $password): string
{
    $tempFile = tempnam(sys_get_temp_dir(), 'mysql_config_');
    if ($tempFile === false) {
        throw new \RuntimeException('Failed to create temporary file');
    }
    
    $config = "[client]\n";
    $config .= "user=" . $user . "\n";
    $config .= "password=" . $password . "\n";
    
    if (file_put_contents($tempFile, $config) === false) {
        throw new \RuntimeException('Failed to write MySQL configuration file');
    }
    
    // Secure the file permissions (readable only by owner)
    chmod($tempFile, 0600);
    
    return $tempFile;
}

/**
 * Safely delete a temporary file.
 *
 * @param string $file Path to file to delete
 * @return void
 */
function secureUnlink(string $file): void
{
    if (file_exists($file)) {
        unlink($file);
    }
}

/**
 * Validate directory path to prevent path traversal.
 *
 * @param string $path The directory path to validate
 * @return string Validated path
 * @throws \InvalidArgumentException If the path is invalid
 */
function validateDirectoryPath(string $path): string
{
    // Prevent path traversal attempts
    if (str_contains($path, '..') || str_contains($path, '~')) {
        throw new \InvalidArgumentException('Invalid directory path: ' . $path);
    }
    
    // Normalize path separators
    $path = str_replace('\\', '/', $path);
    
    // Remove leading/trailing slashes for consistency
    return trim($path, '/');
}