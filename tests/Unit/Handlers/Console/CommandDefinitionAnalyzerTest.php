<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Tests\Unit\Handlers\Console;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Console\CommandDefinitionAnalyzer;
use Symfony\Component\Console\Input\InputDefinition;

#[CoversClass(CommandDefinitionAnalyzer::class)]
final class CommandDefinitionAnalyzerTest extends TestCase
{
    #[Test]
    public function get_definition_returns_null_for_unresolvable_class(): void
    {
        /** @var class-string $class */
        $class = 'NonExistent\\FakeCommand';

        // Reading $signature from Psalm's AST needs a running analysis; without one the lookup degrades to null.
        $this->assertNotInstanceOf(InputDefinition::class, CommandDefinitionAnalyzer::getDefinition($class));
    }
}
