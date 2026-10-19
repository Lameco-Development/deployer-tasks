<?php

declare(strict_types=1);

namespace Deployer\Tests;

use PHPUnit\Framework\TestCase;
use function Deployer\packageManagerSetupCommand;

final class PackageManagerSetupTest extends TestCase
{
    public function testYarnRunsThroughCorepackInstalledWhenNodeLacksIt(): void
    {
        self::assertSame(
            '(command -v corepack >/dev/null || npm install -g corepack) && corepack enable',
            packageManagerSetupCommand('yarn', 'v26.11.1'),
        );
    }

    public function testYarnOnNodeWithoutCorepackSupportKeepsTheGlobalYarn(): void
    {
        self::assertNull(packageManagerSetupCommand('yarn', 'v12.22.12'));
    }

    public function testPnpmIsInstalledWithoutCorepackWhenMissing(): void
    {
        self::assertSame(
            'command -v pnpm >/dev/null || npm install -g pnpm',
            packageManagerSetupCommand('pnpm', 'v26.11.1'),
        );
    }

    public function testNpmShipsWithNode(): void
    {
        self::assertNull(packageManagerSetupCommand('npm', 'v26.11.1'));
    }
}
