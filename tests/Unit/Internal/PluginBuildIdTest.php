<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Internal\PluginBuildId;

#[CoversClass(PluginBuildId::class)]
final class PluginBuildIdTest extends TestCase
{
    private const DEV_VERSION = '4.9999999.9999999.9999999-dev';

    #[Test]
    public function a_new_commit_of_the_same_dev_version_changes_the_build_id(): void
    {
        $this->assertNotSame(
            PluginBuildId::of(self::DEV_VERSION, '126f4e487b6ab5b354881d1d35be8321a5f1b51e'),
            PluginBuildId::of(self::DEV_VERSION, '3fc112bd0000000000000000000000000000000a'),
        );
    }

    #[Test]
    public function the_same_commit_yields_a_stable_build_id(): void
    {
        $this->assertSame(
            PluginBuildId::of(self::DEV_VERSION, '126f4e487b6ab5b354881d1d35be8321a5f1b51e'),
            PluginBuildId::of(self::DEV_VERSION, '126f4e487b6ab5b354881d1d35be8321a5f1b51e'),
        );
    }

    #[Test]
    public function a_missing_reference_falls_back_to_the_version(): void
    {
        $this->assertSame('4.1.0.0', PluginBuildId::of('4.1.0.0', null));
    }
}
