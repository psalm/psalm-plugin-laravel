<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Config\PluginOverrides;
use Psalm\LaravelPlugin\Config\Setting;
use Psalm\LaravelPlugin\Config\SettingType;

#[CoversClass(PluginOverrides::class)]
#[CoversClass(Setting::class)]
#[CoversClass(SettingType::class)]
final class PluginOverridesTest extends TestCase
{
    #[Test]
    public function the_schema_lists_exactly_the_documented_keys(): void
    {
        $keys = \array_map(static fn(Setting $setting): string => $setting->key, Setting::all());

        // cachePath is deliberately absent: it only places the plugin's own cache files.
        $this->assertSame([
            'modelProperties.columnFallback',
            'resolveDynamicWhereClauses',
            'resolveConfigReturnTypes',
            'reportImplicitQueryBuilderCalls',
            'configDirectory',
            'findMissingTranslations',
            'findMissingViews',
            'findUnconfiguredFilesystemDisks',
            'findUnregisteredRouteNames',
            'findSerializedQueuedModels',
            'findOctaneIncompatibleBinding',
            'findPromptInjection',
            'blade',
            'blade.cacheDir',
            'blade.validateViewData',
            'blade.reportUnusedViewData',
            'blade.reportMixedIssues',
            'experimental',
            'failOnInternalError',
        ], $keys);
    }

    #[Test]
    public function every_schema_key_accepts_a_value_of_its_type(): void
    {
        foreach (Setting::all() as $setting) {
            $sample = match ($setting->type) {
                SettingType::Bool => 'true',
                SettingType::Enum => $setting->values[0],
                SettingType::Path, SettingType::PathList => 'some/dir',
            };

            $overrides = PluginOverrides::parse(["{$setting->key}={$sample}"], '--plugin-option');

            $this->assertTrue($overrides->has($setting->key), $setting->key);
        }
    }

    #[Test]
    public function values_are_typed_by_the_schema(): void
    {
        $overrides = PluginOverrides::parse([
            'blade=false',
            'findOctaneIncompatibleBinding=true',
            'modelProperties.columnFallback=none',
            'blade.cacheDir=/tmp/blade shadows',
        ], '--plugin-option');

        $this->assertFalse($overrides->bool('blade'));
        $this->assertTrue($overrides->bool('findOctaneIncompatibleBinding'));
        $this->assertSame('none', $overrides->string('modelProperties.columnFallback'));
        $this->assertSame('/tmp/blade shadows', $overrides->string('blade.cacheDir'));
        $this->assertNull($overrides->bool('experimental'));
        $this->assertFalse($overrides->has('experimental'));
    }

    #[Test]
    public function a_value_keeps_everything_after_the_first_equals_sign(): void
    {
        $this->assertSame('a=b=c', PluginOverrides::parse(['blade.cacheDir=a=b=c'], 'x')->string('blade.cacheDir'));
    }

    #[Test]
    public function a_repeated_scalar_key_last_wins_and_a_list_key_accumulates(): void
    {
        $overrides = PluginOverrides::parse([
            'blade=true', 'blade=false',
            'configDirectory=a', 'configDirectory=packages/*/config',
        ], '--plugin-option');

        $this->assertFalse($overrides->bool('blade'));
        $this->assertSame(['a', 'packages/*/config'], $overrides->list('configDirectory'));
    }

    #[Test]
    public function every_token_is_validated_even_when_a_later_repeat_shadows_it(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        PluginOverrides::parse(['blade=maybe', 'blade=true'], '--plugin-option');
    }

    #[Test]
    public function a_higher_layer_replaces_a_list_wholesale_and_only_the_keys_it_sets(): void
    {
        $env = PluginOverrides::parse(['configDirectory=a', 'configDirectory=b', 'blade=true', 'experimental=true'], 'env');
        $cli = PluginOverrides::parse(['configDirectory=c', 'blade=false'], 'cli');

        $merged = $env->over($cli);

        $this->assertSame(['c'], $merged->list('configDirectory'));
        $this->assertFalse($merged->bool('blade'));
        $this->assertTrue($merged->bool('experimental'));
        $this->assertSame(['a', 'b'], $env->list('configDirectory'), 'merging must not mutate the lower layer');
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidTokens(): iterable
    {
        yield 'unknown key' => ['bladee=true', "unknown key 'bladee'"];
        yield 'unknown key lists the valid ones' => ['nope=1', 'modelProperties.columnFallback'];
        yield 'cachePath is not a key' => ['cachePath=/tmp/x', "unknown key 'cachePath'"];
        yield 'bad bool' => ['blade=yes', "Valid values: 'true', 'false'"];
        yield 'uppercase bool' => ['blade=TRUE', "invalid value 'TRUE'"];
        yield 'auto is not accepted' => ['findPromptInjection=auto', "invalid value 'auto'"];
        yield 'bad enum' => ['modelProperties.columnFallback=db', "Valid values: 'migrations', 'none'"];
        yield 'missing equals' => ['blade', 'expected KEY=VALUE'];
        yield 'empty key' => ['=true', 'expected KEY=VALUE'];
        yield 'empty value' => ['blade=', 'expected KEY=VALUE'];
        yield 'empty value for a list' => ['configDirectory=', 'expected KEY=VALUE'];
    }

    #[Test]
    #[DataProvider('invalidTokens')]
    public function invalid_tokens_fail_loudly_and_name_their_origin(string $token, string $message): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^--plugin-option: .*' . \preg_quote($message, '/') . '/s');

        PluginOverrides::parse([$token], '--plugin-option');
    }

    #[Test]
    public function the_env_grammar_is_whitespace_separated_and_blank_means_no_overrides(): void
    {
        $overrides = PluginOverrides::fromEnv("  blade=true \t experimental=false\nconfigDirectory=a  configDirectory=b ");

        $this->assertTrue($overrides->bool('blade'));
        $this->assertFalse($overrides->bool('experimental'));
        $this->assertSame(['a', 'b'], $overrides->list('configDirectory'));

        foreach ([null, '', '   ', "\n"] as $blank) {
            $this->assertFalse(PluginOverrides::fromEnv($blank)->has('blade'));
        }
    }

    #[Test]
    public function the_env_grammar_rejects_a_value_starting_with_a_double_quote(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/^PSALM_LARAVEL_OPTIONS: .*quot/s');

        PluginOverrides::fromEnv('blade.cacheDir="/tmp/a b"');
    }

    #[Test]
    public function a_double_quote_inside_an_env_value_is_taken_verbatim(): void
    {
        $this->assertSame('/tmp/a"b', PluginOverrides::fromEnv('blade.cacheDir=/tmp/a"b')->string('blade.cacheDir'));
    }

    #[Test]
    public function env_errors_name_the_variable(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches("/^PSALM_LARAVEL_OPTIONS: unknown key 'bladee'/");

        PluginOverrides::fromEnv('bladee=true');
    }

    #[Test]
    public function the_private_cli_layer_carries_values_with_whitespace_through_json(): void
    {
        $json = \json_encode(['blade.cacheDir=/tmp/blade shadows', 'blade=true'], \JSON_THROW_ON_ERROR);

        $overrides = PluginOverrides::fromEnvironment([PluginOverrides::CLI_ENV_VAR => $json]);

        $this->assertSame('/tmp/blade shadows', $overrides->string('blade.cacheDir'));
        $this->assertTrue($overrides->bool('blade'));
    }

    #[Test]
    public function the_environment_edge_layers_cli_over_env(): void
    {
        $overrides = PluginOverrides::fromEnvironment([
            PluginOverrides::ENV_VAR => 'blade=true experimental=true',
            PluginOverrides::CLI_ENV_VAR => '["blade=false"]',
        ]);

        $this->assertFalse($overrides->bool('blade'));
        $this->assertTrue($overrides->bool('experimental'));
        $this->assertFalse(PluginOverrides::fromEnvironment([])->has('blade'));
    }

    #[Test]
    #[DataProvider('malformedTransport')]
    public function a_malformed_private_transport_is_rejected(string $json): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/PSALM_LARAVEL_CLI_OPTIONS/');

        PluginOverrides::fromEnvironment([PluginOverrides::CLI_ENV_VAR => $json]);
    }

    /** @return iterable<string, array{string}> */
    public static function malformedTransport(): iterable
    {
        yield 'not json' => ['{'];
        yield 'not a list' => ['{"blade":"true"}'];
        yield 'non-string entry' => ['[1]'];
    }
}
