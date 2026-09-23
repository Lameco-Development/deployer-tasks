<?php

declare(strict_types=1);

namespace Deployer\Tests;

use PHPUnit\Framework\TestCase;

use function Deployer\localCheckoutProblem;
use function Deployer\runsInCi;
use function Deployer\targetProblem;

final class LocalCheckoutProblemTest extends TestCase
{
    private const string SHA = '1111111aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
    private const string OTHER_SHA = '2222222bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    public function testCleanCheckoutAtTheOriginTipMayDeploy(): void
    {
        self::assertNull(localCheckoutProblem('main', self::SHA, self::SHA, ''));
    }

    public function testBranchMissingOnOriginStopsTheDeploy(): void
    {
        $problem = localCheckoutProblem('main', self::SHA, '', '');

        self::assertNotNull($problem);
        self::assertStringContainsString('does not exist on origin', $problem);
    }

    public function testLocalBranchThatDiffersFromOriginStopsTheDeploy(): void
    {
        $problem = localCheckoutProblem('main', self::SHA, self::OTHER_SHA, '');

        self::assertNotNull($problem);
        self::assertStringContainsString('Local main (1111111) differs from origin/main (2222222)', $problem);
    }

    public function testUncommittedChangesStopTheDeploy(): void
    {
        $problem = localCheckoutProblem('main', self::SHA, self::SHA, " M templates/index.twig\n?? notes.txt\n");

        self::assertNotNull($problem);
        self::assertStringContainsString('uncommitted changes', $problem);
    }

    public function testRunsInCiFollowsGithubActions(): void
    {
        $previous = getenv('GITHUB_ACTIONS');

        putenv('GITHUB_ACTIONS=true');
        self::assertTrue(runsInCi());

        putenv('GITHUB_ACTIONS');
        self::assertFalse(runsInCi());

        if ($previous !== false) {
            putenv('GITHUB_ACTIONS=' . $previous);
        }
    }

    public function testTargetEqualToTheHostBranchMayDeploy(): void
    {
        self::assertNull(targetProblem('main', 'main'));
    }

    public function testAnotherTargetThanTheHostBranchStopsTheDeploy(): void
    {
        $problem = targetProblem('hotfix/x', 'main');

        self::assertNotNull($problem);
        self::assertStringContainsString('would ship "hotfix/x" instead of the host branch "main"', $problem);
        self::assertStringContainsString('-o branch=hotfix/x', $problem);
    }
}
