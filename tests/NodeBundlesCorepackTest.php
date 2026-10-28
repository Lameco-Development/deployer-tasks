<?php

declare(strict_types=1);

namespace Deployer\Tests;

use PHPUnit\Framework\TestCase;

use function Deployer\nodeBundlesCorepack;

final class NodeBundlesCorepackTest extends TestCase
{
    public function testNode24AndOlderBundleCorepack(): void
    {
        self::assertTrue(nodeBundlesCorepack('v24.22.1'));
        self::assertTrue(nodeBundlesCorepack('v16.9.0'));
    }

    public function testNode25AndNewerNeedCorepackInstalled(): void
    {
        self::assertFalse(nodeBundlesCorepack('v25.0.0'));
        self::assertFalse(nodeBundlesCorepack('v26.11.1'));
        self::assertFalse(nodeBundlesCorepack('26.11.1'));
    }
}
