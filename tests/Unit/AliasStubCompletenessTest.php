<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit;

use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Plugin;
use Psalm\LaravelPlugin\PluginConfig;

#[CoversClass(Plugin::class)]
final class AliasStubCompletenessTest extends TestCase
{
    #[Test]
    public function all_default_aliases_are_present_in_generated_stub(): void
    {
        $stubPath = Plugin::getAliasStubLocation(PluginConfig::fromXml(null));

        if (!\file_exists($stubPath)) {
            self::markTestSkipped('Alias stub not generated yet (run the plugin first).');
        }

        $stubContent = \file_get_contents($stubPath);
        $this->assertIsString($stubContent);

        $defaultAliases = Facade::defaultAliases();

        foreach ($defaultAliases as $alias => $fqcn) {
            if (\str_contains($alias, '\\')) {
                continue;
            }

            $this->assertStringContainsString("class {$alias} extends", $stubContent, "Missing alias stub for {$alias} -> {$fqcn}");
        }
    }
}
