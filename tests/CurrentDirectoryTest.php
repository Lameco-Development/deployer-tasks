<?php

declare(strict_types=1);

namespace Deployer\Tests;

use PHPUnit\Framework\TestCase;

use function Deployer\classifyCurrentDirectory;

final class CurrentDirectoryTest extends TestCase
{
    private const string PLACEHOLDER = './web/index.html';

    public function testDirectoryWithoutFilesIsAnEmptyDocroot(): void
    {
        self::assertSame('empty', classifyCurrentDirectory('', self::PLACEHOLDER, false));
        self::assertSame('empty', classifyCurrentDirectory("\n", self::PLACEHOLDER, false));
    }

    public function testOnlyThePlaceholderPageIsThePlaceholder(): void
    {
        self::assertSame('placeholder', classifyCurrentDirectory("./web/index.html\n", self::PLACEHOLDER, true));
    }

    public function testAnIndexPageWithoutThePlaceholderTextIsRealContent(): void
    {
        self::assertSame('other', classifyCurrentDirectory('./web/index.html', self::PLACEHOLDER, false));
    }

    public function testThePlaceholderNextToOtherFilesIsRealContent(): void
    {
        self::assertSame(
            'other',
            classifyCurrentDirectory("./craft\n./web/index.html", self::PLACEHOLDER, true),
        );
    }

    public function testAnyOtherSingleFileIsRealContent(): void
    {
        self::assertSame('other', classifyCurrentDirectory('./web/index.php', self::PLACEHOLDER, true));
    }
}
