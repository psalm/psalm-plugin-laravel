<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Config\ColumnFallback;
use Psalm\LaravelPlugin\Config\PluginOverrides;

#[CoversClass(PluginOverrides::class)]
final class PluginOverridesTest extends TestCase
{
    #[Test]
    public function the_column_fallback_values_match_the_enum(): void
    {
        $this->assertSame(
            \array_map(static fn(ColumnFallback $case): string => $case->value, ColumnFallback::cases()),
            PluginOverrides::KEYS['modelProperties.columnFallback'],
        );
    }

    #[Test]
    public function the_grammar_is_whitespace_separated_last_wins_and_blank_means_none(): void
    {
        $overrides = PluginOverrides::parse("  blade=true \t experimental=false\nmodelProperties.columnFallback=none blade=false ");

        $this->assertFalse($overrides->bool('blade'));
        $this->assertFalse($overrides->bool('experimental'));
        $this->assertSame('none', $overrides->string('modelProperties.columnFallback'));
        $this->assertNull($overrides->bool('findMissingViews'));
        $this->assertFalse($overrides->has('findMissingViews'));

        foreach (['', '   ', "\n"] as $blank) {
            $this->assertFalse(PluginOverrides::parse($blank)->has('blade'));
        }
    }

    #[Test]
    public function every_token_is_validated_even_when_a_later_repeat_shadows_it(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PluginOverrides::parse('blade=maybe blade=true');
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidTokens(): iterable
    {
        yield 'unknown key lists the supported ones' => ['bladee=true', "unknown key 'bladee'. Supported keys: modelProperties.columnFallback,"];
        yield 'configDirectory is XML-only' => ['configDirectory=a', "unknown key 'configDirectory'"];
        yield 'bad bool' => ['blade=yes', "invalid value 'yes' for key 'blade'. Valid values: true, false."];
        yield 'bad enum' => ['modelProperties.columnFallback=db', 'Valid values: migrations, none.'];
        yield 'missing equals' => ['blade', "'blade' is not KEY=VALUE"];
    }

    #[Test]
    #[DataProvider('invalidTokens')]
    public function invalid_tokens_fail_loudly_and_name_their_origin(string $token, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^PSALM_LARAVEL_INTERNAL_OPTIONS: .*' . \preg_quote($message, '/') . '/s');

        PluginOverrides::parse($token);
    }

    #[Test]
    public function the_origin_is_configurable(): void
    {
        $this->expectExceptionMessageMatches("/^--plugin-option: unknown key 'x'/");

        PluginOverrides::parse('x=1', '--plugin-option');
    }
}
