<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit;

use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Plugin;

#[CoversClass(Plugin::class)]
final class AliasStubCompletenessTest extends TestCase
{
    public function test_all_default_aliases_are_present_in_generated_stub(): void
    {
        $stubPath = Plugin::getAliasStubLocation();

        if (!\file_exists($stubPath)) {
            self::markTestSkipped('Alias stub not generated yet (run the plugin first).');
        }

        $stubContent = \file_get_contents($stubPath);
        self::assertIsString($stubContent);

        $defaultAliases = Facade::defaultAliases();

        foreach ($defaultAliases as $alias => $fqcn) {
            if (\str_contains($alias, '\\')) {
                continue;
            }

            self::assertStringContainsString(
                "class {$alias} extends",
                $stubContent,
                "Missing alias stub for {$alias} -> {$fqcn}",
            );
        }
    }
}
