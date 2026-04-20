<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Cli\Integrations\Ci;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Cli\Integrations\Ci\CiPlan;
use Psalm\LaravelPlugin\Cli\Integrations\Ci\CiTargetInterface;
use Psalm\LaravelPlugin\Cli\Integrations\Ci\CiTargetRegistry;
use Psalm\LaravelPlugin\Cli\Integrations\Ci\GitHubActionsTarget;
use Psalm\LaravelPlugin\Cli\Integrations\Ci\UnknownCiTargetException;

#[CoversClass(CiTargetRegistry::class)]
#[CoversClass(UnknownCiTargetException::class)]
final class CiTargetRegistryTest extends TestCase
{
    #[Test]
    public function default_registry_exposes_github(): void
    {
        $registry = CiTargetRegistry::default();

        $this->assertSame(['github'], $registry->ids());
        $this->assertInstanceOf(GitHubActionsTarget::class, $registry->resolve('github', \sys_get_temp_dir()));
    }

    #[Test]
    public function ci_alias_returns_detected_target(): void
    {
        $detectingTarget = $this->makeTarget('gitlab', detects: true);
        $nonDetectingTarget = $this->makeTarget('github', detects: false);

        $registry = new CiTargetRegistry([$nonDetectingTarget, $detectingTarget]);

        $this->assertSame($detectingTarget, $registry->resolve('ci', \sys_get_temp_dir()));
    }

    #[Test]
    public function ci_alias_falls_back_to_first_registered_when_nothing_detected(): void
    {
        $fallback = $this->makeTarget('github', detects: false);
        $other = $this->makeTarget('gitlab', detects: false);

        $registry = new CiTargetRegistry([$fallback, $other]);

        $this->assertSame($fallback, $registry->resolve('ci', \sys_get_temp_dir()));
    }

    #[Test]
    public function explicit_name_resolves_regardless_of_detection(): void
    {
        $explicit = $this->makeTarget('gitlab', detects: false);
        $other = $this->makeTarget('github', detects: true);

        $registry = new CiTargetRegistry([$other, $explicit]);

        $this->assertSame($explicit, $registry->resolve('gitlab', \sys_get_temp_dir()));
    }

    #[Test]
    public function unknown_target_lists_supported_ids_in_error(): void
    {
        $registry = new CiTargetRegistry([$this->makeTarget('github', detects: false)]);

        try {
            $registry->resolve('bitbucket', \sys_get_temp_dir());
            $this->fail('Expected UnknownCiTargetException was not thrown.');
        } catch (UnknownCiTargetException $unknownCiTargetException) {
            $this->assertSame('bitbucket', $unknownCiTargetException->name);
            $this->assertSame(['github'], $unknownCiTargetException->supportedIds);
            $this->assertStringContainsString('github', $unknownCiTargetException->getMessage());
            $this->assertStringContainsString('bitbucket', $unknownCiTargetException->getMessage());
            $this->assertStringContainsString('ci', $unknownCiTargetException->getMessage());
        }
    }

    #[Test]
    public function empty_target_list_is_rejected_at_construction(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('CiTargetRegistry requires at least one target');

        new CiTargetRegistry([]);
    }

    #[Test]
    public function duplicate_target_ids_are_rejected_at_construction(): void
    {
        // Silently last-wins would mask a registration bug and make detection
        // order dependent on construction sequence. Fail loudly instead.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Duplicate CI target id "github"');

        new CiTargetRegistry([
            $this->makeTarget('github', detects: false),
            $this->makeTarget('github', detects: true),
        ]);
    }

    #[Test]
    public function reserved_ci_id_is_rejected_at_construction(): void
    {
        // `ci` is the auto-detect alias in resolve(); adapter using it would
        // be unreachable via the CLI.
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('reserved for the auto-detect alias');

        new CiTargetRegistry([$this->makeTarget('ci', detects: false)]);
    }

    /**
     * Minimal in-memory target used purely as a registry placeholder. The
     * production registry never inspects plan()/displayName() while resolving
     * names, so those can return stubs without affecting what we test here.
     */
    private function makeTarget(string $id, bool $detects): CiTargetInterface
    {
        return new class ($id, $detects) implements CiTargetInterface {
            public function __construct(
                private readonly string $id,
                private readonly bool $detects,
            ) {}

            #[\Override]
            public function id(): string
            {
                return $this->id;
            }

            #[\Override]
            public function displayName(): string
            {
                return \ucfirst($this->id);
            }

            #[\Override]
            public function detect(string $projectRoot): bool
            {
                return $this->detects;
            }

            #[\Override]
            public function plan(string $projectRoot): CiPlan
            {
                return new CiPlan($projectRoot . '/test.yml', '', false);
            }
        };
    }
}
