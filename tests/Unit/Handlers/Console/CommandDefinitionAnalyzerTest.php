<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Tests\Unit\Handlers\Console;

use Illuminate\Console\Parser;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Console\CommandDefinitionAnalyzer;
use Symfony\Component\Console\Input\InputDefinition;

/**
 * Tests for the type-mapping logic in CommandDefinitionAnalyzer.
 *
 * Note: most of the analyzer's behavior (reading $signature from Psalm's AST) requires
 * a running Psalm analysis context. These tests verify the parsing/mapping layer
 * by calling Laravel's Parser directly and checking the resulting InputDefinition.
 */
#[CoversClass(CommandDefinitionAnalyzer::class)]
final class CommandDefinitionAnalyzerTest extends TestCase
{
    /**
     * Helper to parse a Laravel signature into an InputDefinition.
     */
    private static function parseSignature(string $signature): InputDefinition
    {
        /** @var array{0: string, 1: list<\Symfony\Component\Console\Input\InputArgument>, 2: list<\Symfony\Component\Console\Input\InputOption>} $parsed */
        $parsed = Parser::parse($signature);

        $definition = new InputDefinition();
        $definition->addArguments($parsed[1]);
        $definition->addOptions($parsed[2]);

        return $definition;
    }

    public function test_required_argument(): void
    {
        $definition = self::parseSignature('test:cmd {email : The user email}');
        $argument = $definition->getArgument('email');

        $this->assertTrue($argument->isRequired());
        $this->assertFalse($argument->isArray());
    }

    public function test_optional_argument(): void
    {
        $definition = self::parseSignature('test:cmd {role? : Optional role}');
        $argument = $definition->getArgument('role');

        $this->assertFalse($argument->isRequired());
        $this->assertFalse($argument->isArray());
    }

    public function test_array_argument(): void
    {
        $definition = self::parseSignature('test:cmd {tags?* : Tags array}');
        $argument = $definition->getArgument('tags');

        $this->assertTrue($argument->isArray());
    }

    public function test_flag_option(): void
    {
        $definition = self::parseSignature('test:cmd {--F|force : Force flag}');
        $option = $definition->getOption('force');

        $this->assertFalse($option->acceptValue());
    }

    public function test_value_accepting_option(): void
    {
        $definition = self::parseSignature('test:cmd {--limit= : Limit value}');
        $option = $definition->getOption('limit');

        $this->assertTrue($option->acceptValue());
        $this->assertTrue($option->isValueOptional());
    }

    public function test_value_accepting_option_with_default(): void
    {
        $definition = self::parseSignature('test:cmd {--format=json : Format}');
        $option = $definition->getOption('format');

        $this->assertTrue($option->acceptValue());
        $this->assertSame('json', $option->getDefault());
    }

    public function test_array_option(): void
    {
        $definition = self::parseSignature('test:cmd {--ids=* : IDs}');
        $option = $definition->getOption('ids');

        $this->assertTrue($option->isArray());
    }

    public function test_undefined_argument_throws(): void
    {
        $definition = self::parseSignature('test:cmd {email}');

        $this->expectException(\Symfony\Component\Console\Exception\InvalidArgumentException::class);
        $definition->getArgument('nonexistent');
    }

    public function test_undefined_option_throws(): void
    {
        $definition = self::parseSignature('test:cmd {--force}');

        $this->expectException(\Symfony\Component\Console\Exception\InvalidArgumentException::class);
        $definition->getOption('nonexistent');
    }

    public function test_has_argument(): void
    {
        $definition = self::parseSignature('test:cmd {email}');

        $this->assertTrue($definition->hasArgument('email'));
        $this->assertFalse($definition->hasArgument('nonexistent'));
    }

    public function test_has_option(): void
    {
        $definition = self::parseSignature('test:cmd {--force}');

        $this->assertTrue($definition->hasOption('force'));
        $this->assertFalse($definition->hasOption('nonexistent'));
    }

    public function test_get_definition_returns_null_for_unresolvable_class(): void
    {
        /** @var class-string $class */
        $class = 'NonExistent\\FakeCommand';

        // Without a Psalm analysis context, getDefinition() gracefully returns null
        $this->assertNull(CommandDefinitionAnalyzer::getDefinition($class));
    }
}
