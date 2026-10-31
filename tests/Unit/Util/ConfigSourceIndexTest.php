<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Util;

use PhpParser\PrettyPrinter\Standard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Config\ConfigSource;
use Psalm\LaravelPlugin\Handlers\Config\ConfigSourceIndex;
use Psalm\LaravelPlugin\Handlers\Config\ConfigValueReflector;
use Psalm\LaravelPlugin\Handlers\Config\EnvExpressionTyper;
use Tests\Psalm\LaravelPlugin\Unit\Concerns\CopiesFixtureDirectories;

#[CoversClass(ConfigSourceIndex::class)]
#[CoversClass(ConfigSource::class)]
final class ConfigSourceIndexTest extends TestCase
{
    use CopiesFixtureDirectories;

    /** @var list<string> */
    private array $directories = [];

    #[\Override]
    protected function tearDown(): void
    {
        foreach ($this->directories as $directory) {
            self::removeDirectory($directory);
        }
    }

    #[Test]
    public function app_file_wins_and_framework_base_fills_missing_top_level_keys(): void
    {
        $index = ConfigSourceIndex::fromDirectories(
            $this->directory(['app.php' => "<?php return ['x', 'name' => 'App'];"]),
            $this->directory(['app.php' => "<?php return [env('B'), 'name' => env('N'), 'url' => env('U')];"]),
        );

        $this->assertSame("'App'", $this->code($index->find('app.name')));
        $this->assertSame("env('U')", $this->code($index->find('app.url')));
        // array_merge() renumbers integer keys: the runtime `app.0` is the base's env('B'), not 'x'.
        $this->assertNull($index->find('app.0'));
    }

    #[Test]
    public function base_is_no_fallback_below_the_top_level_of_a_regular_key(): void
    {
        $index = ConfigSourceIndex::fromDirectories(
            $this->directory(['app.php' => "<?php return ['maintenance' => ['driver' => 'file']];"]),
            $this->directory(['app.php' => "<?php return ['maintenance' => ['driver' => env('D'), 'store' => env('S')]];"]),
        );

        $this->assertSame("'file'", $this->code($index->find('app.maintenance.driver')));
        $this->assertNull($index->find('app.maintenance.store'));
    }

    #[Test]
    public function base_fills_a_mergeable_option_one_level_deeper_only(): void
    {
        $index = ConfigSourceIndex::fromDirectories(
            $this->directory(['database.php' => "<?php return ['connections' => ['mysql' => ['host' => 'db']]];"]),
            $this->directory(['database.php' => <<<'PHP'
                <?php return ['connections' => [
                    'mysql' => ['host' => env('H'), 'port' => env('P')],
                    'pgsql' => ['host' => env('PG')],
                ]];
                PHP]),
        );

        $this->assertSame("'db'", $this->code($index->find('database.connections.mysql.host')));
        $this->assertSame("env('PG')", $this->code($index->find('database.connections.pgsql.host')));
        // The app's mysql entry replaced the base one wholesale.
        $this->assertNull($index->find('database.connections.mysql.port'));
    }

    #[Test]
    public function without_an_app_file_the_base_file_is_the_source(): void
    {
        $index = ConfigSourceIndex::fromDirectories(
            $this->directory(['other.php' => '<?php return [];']),
            $this->directory(['cache.php' => "<?php return ['prefix' => env('P')];"]),
        );

        $this->assertSame("env('P')", $this->code($index->find('cache.prefix')));
    }

    #[Test]
    public function nested_directory_file_defines_a_dotted_key_over_its_parent_file(): void
    {
        $index = ConfigSourceIndex::fromDirectories($this->directory([
            'foo.php' => "<?php return ['bar' => env('X'), 'baz' => 'kept'];",
            'foo/bar.php' => "<?php return ['qux' => env('Q')];",
            'modules/billing.php' => "<?php return ['key' => env('K')];",
        ]));

        $this->assertSame("env('Q')", $this->code($index->find('foo.bar.qux')));
        $this->assertSame("'kept'", $this->code($index->find('foo.baz')));
        $this->assertSame("env('Q')", $this->code($index->find('foo')?->child('bar')?->child('qux')));
        $this->assertSame("env('K')", $this->code($index->find('modules.billing.key')));
    }

    #[Test]
    public function a_framework_config_set_after_the_app_files_replaces_nested_files_below_it(): void
    {
        $base = $this->directory(['services.php' => "<?php return ['postmark' => ['token' => env('T')]];"]);
        $nested = ['services/postmark.php' => "<?php return ['token' => env('A')];"];

        // Without an app services.php, LoadConfiguration sets the base `services` after the app files.
        $index = ConfigSourceIndex::fromDirectories($this->directory($nested), $base);
        $this->assertSame("env('T')", $this->code($index->find('services.postmark.token')));

        $index = ConfigSourceIndex::fromDirectories($this->directory($nested + ['services.php' => '<?php return [];']), $base);
        $this->assertSame("env('A')", $this->code($index->find('services.postmark.token')));
    }

    #[Test]
    public function a_dotted_literal_key_is_one_segment_not_a_nested_file(): void
    {
        $index = ConfigSourceIndex::fromDirectories($this->directory([
            'demo.php' => "<?php return ['foo.bar' => env('T'), 'baz.qux' => ['c' => env('C')]];",
            'demo/foo/bar.php' => "<?php return ['other' => 1];",
            'demo/baz/qux/c.php' => "<?php return ['other' => 1];",
        ]));

        $this->assertSame("env('T')", $this->code($index->find('demo')?->child('foo.bar')));
        // The loader sets the nested file at `demo.baz.qux.c`, below a `baz` key the literal array lacks.
        $this->assertSame("env('C')", $this->code($index->find('demo')?->child('baz.qux')?->child('c')));
    }

    #[Test]
    public function integer_like_string_keys_match_as_php_stores_them(): void
    {
        $index = ConfigSourceIndex::fromDirectories($this->directory([
            'codes.php' => "<?php return ['404' => env('A'), 'x', '007' => env('B')];",
        ]));

        $this->assertSame("env('A')", $this->code($index->find('codes.404')));
        $this->assertSame("'x'", $this->code($index->find('codes.405')));
        $this->assertSame("env('B')", $this->code($index->find('codes.007')));
    }

    #[Test]
    #[DataProvider('opaqueFiles')]
    public function a_file_without_one_unconditional_array_return_has_no_source(string $code): void
    {
        $index = ConfigSourceIndex::fromDirectories($this->directory(['app.php' => $code]));

        $this->assertNull($index->find('app.key'));
    }

    /** @return iterable<string, array{string}> */
    public static function opaqueFiles(): iterable
    {
        yield 'computed return' => ["<?php return array_merge(['key' => env('K')], []);"];
        yield 'conditional return' => ["<?php if (PHP_OS === 'Linux') { return ['key' => 'a']; } return ['key' => env('K')];"];
        yield 'two returns' => ["<?php return ['key' => 'a']; return ['key' => env('K')];"];
        yield 'parse error' => ["<?php return ['key' => env('K')"];
    }

    #[Test]
    public function a_return_inside_a_closure_does_not_make_the_file_opaque(): void
    {
        $index = ConfigSourceIndex::fromDirectories($this->directory([
            'app.php' => "<?php return ['resolver' => function () { return 1; }, 'key' => env('K')];",
        ]));

        $this->assertSame("env('K')", $this->code($index->find('app.key')));
    }

    #[Test]
    public function an_array_with_a_spread_or_computed_key_is_an_opaque_subtree(): void
    {
        $index = ConfigSourceIndex::fromDirectories($this->directory([
            'app.php' => "<?php return ['spread' => [...\$x, 'k' => env('K')], 'computed' => [PHP_OS => env('K')], 'self' => [self::class => env('K')], 'const' => [Foo::BAR => env('K')], 'sibling' => env('S')];",
        ]));

        $this->assertNull($index->find('app.spread.k'));
        $this->assertNull($index->find('app.computed.Linux'));
        $this->assertNull($index->find('app.self.self'));
        $this->assertNull($index->find('app.const.Foo'));
        $this->assertSame("env('S')", $this->code($index->find('app.sibling')));
    }

    #[Test]
    public function names_are_resolved_before_the_typer_sees_them(): void
    {
        $index = ConfigSourceIndex::fromDirectories($this->directory([
            'app.php' => "<?php use function Vendor\\Package\\env; return ['key' => env('K')];",
        ]));

        $source = $index->find('app.key');
        $this->assertInstanceOf(ConfigSource::class, $source);
        $this->assertFalse(EnvExpressionTyper::readsEnv($source->expression()));
    }

    #[Test]
    public function an_unqualified_env_call_in_a_namespace_has_no_source_type(): void
    {
        // A namespaced env() declared in any file loaded earlier wins at runtime, and under
        // config:cache no config file runs at all, so the runtime value is reflected instead.
        $index = ConfigSourceIndex::fromDirectories($this->directory([
            'demo.php' => "<?php namespace PsalmLaravelConfig; return ['token' => env('TOKEN'), 'url' => \\env('URL')];",
        ]));

        $this->assertSame('string', ConfigValueReflector::reflect('fixed', $index->find('demo.token'))->getId());
        $this->assertSame('null|string', ConfigValueReflector::reflect('https://a', $index->find('demo.url'))->getId());
    }

    #[Test]
    public function a_function_declared_inside_a_closure_leaves_the_other_keys_their_source(): void
    {
        $index = ConfigSourceIndex::fromDirectories($this->directory([
            'demo.php' => "<?php return ['hook' => function () { function psalmLaravelDeferred(): void {} }, 'port' => 8080];",
        ]));

        $this->assertSame('int', ConfigValueReflector::reflect(8080, $index->find('demo.port'))->getId());
    }

    #[Test]
    public function a_variable_holds_env_only_when_the_file_may_assign_env_to_it(): void
    {
        $index = ConfigSourceIndex::fromDirectories($this->directory([
            'plain.php' => "<?php \$port = 8080; return ['port' => \$port, 'key' => env('KEY'), 'url' => rtrim(env('URL'), '/'), 'upper' => strtoupper(\$port)];",
            'env.php' => "<?php \$port = env('PORT'); return ['port' => \$port, 'timeout' => (int) \$port];",
            'assign.php' => "<?php return ['token' => \$token = env('TOKEN'), 'copy' => \$token];",
            'append.php' => "<?php \$t = 'x'; return ['append' => \$t .= env('T'), 'appended' => \$t];",
            'include.php' => "<?php \$shared = require __DIR__ . '/../shared.php'; return ['token' => \$shared['token']];",
            'required.php' => "<?php \$port = 8080; return ['shared' => require __DIR__ . '/../shared.php', 'port' => \$port];",
            'callable.php' => "<?php \$read = env(...); return ['token' => \$read('TOKEN')];",
            'namespaced.php' => "<?php namespace PsalmLaravelConfig; \$read = env(...); \$get = getenv(...); \$port = 8080; return ['port' => \$port];",
            // A closure stored as a config value never runs while the file loads, nor do the declarations in it.
            'deferred.php' => <<<'PHP'
                <?php $port = 8080; return [
                    'reader' => function () use (&$port) { $port = env('T'); },
                    'arrow' => fn () => $port = env('U'),
                    'nested' => ['hook' => function () use (&$port) {
                        function psalmLaravelNeverDeclared(object $s): void { $s->port = env('V'); }
                        class PsalmLaravelNeverDeclared { public function __construct(object $s) { $s->port = env('W'); } }
                    }],
                    'port' => $port,
                ];
                PHP,
        ]));

        $this->assertSame('int', ConfigValueReflector::reflect(8080, $index->find('plain.port'))->getId());
        $this->assertSame('mixed', ConfigValueReflector::reflect('8080', $index->find('env.port'))->getId());
        $this->assertSame('int', ConfigValueReflector::reflect(8080, $index->find('env.timeout'))->getId());
        $this->assertSame('mixed', ConfigValueReflector::reflect('secret', $index->find('assign.copy'))->getId());
        $this->assertSame('mixed', ConfigValueReflector::reflect('xsecret', $index->find('append.appended'))->getId());
        $this->assertSame('mixed', ConfigValueReflector::reflect('secret', $index->find('include.token'))->getId());
        $this->assertSame('mixed', ConfigValueReflector::reflect(8080, $index->find('required.port'))->getId());
        $this->assertSame('mixed', ConfigValueReflector::reflect('secret', $index->find('callable.token'))->getId());
        $this->assertSame('int', ConfigValueReflector::reflect(8080, $index->find('namespaced.port'))->getId());
        $this->assertSame('int', ConfigValueReflector::reflect(8080, $index->find('deferred.port'))->getId());
    }

    #[Test]
    #[DataProvider('codeRunningWhileTheFileLoads')]
    public function code_the_return_runs_may_leave_env_in_a_variable(string $code): void
    {
        $index = ConfigSourceIndex::fromDirectories($this->directory(['demo.php' => $code]));

        // Unset, `TOKEN=abc` and `TOKEN=true`.
        foreach ([null, 'abc', true] as $runtime) {
            $this->assertSame('mixed', ConfigValueReflector::reflect($runtime, $index->find('demo.port'))->getId());
        }
    }

    /** @return iterable<string, array{string}> */
    public static function codeRunningWhileTheFileLoads(): iterable
    {
        $state = "<?php \$state = (object) ['port' => 8080]; return [";
        $port = "'port' => \$state->port];";

        yield 'invoked closure' => [$state . "'init' => (function () use (\$state) { \$state->port = env('TOKEN'); })(), " . $port];
        yield 'invoked arrow fn' => [$state . "'init' => (fn () => \$state->port = env('TOKEN'))(), " . $port];
        yield 'anonymous class constructor' => [$state . "'init' => new class(\$state) { public function __construct(object \$s) { \$s->port = env('TOKEN'); } }, " . $port];
        yield 'captured array holding a reference' => ["<?php \$port = 8080; \$refs = [&\$port]; return ['init' => (function () use (\$refs) { \$refs[0] = env('TOKEN'); })(), 'port' => \$port];"];
        yield 'invoked closure capturing by reference' => ["<?php \$port = 8080; return ['init' => (function () use (&\$port) { \$port = env('TOKEN'); })(), 'port' => \$port];"];
        yield 'closure passed to a call' => [$state . "'init' => array_map(function (\$v) use (\$state) { \$state->port = env('TOKEN'); return \$v; }, [1]), " . $port];
        yield 'closure invoked from an array' => [$state . "'init' => [function () use (\$state) { \$state->port = env('TOKEN'); }][0](), " . $port];
        yield 'env passed to an invoked closure' => [$state . "'init' => (function (\$v) use (\$state) { \$state->port = \$v; })(env('TOKEN')), " . $port];
        yield 'computed key' => [$state . "'nested' => [(function () use (\$state) { \$state->port = env('TOKEN'); return 'k'; })() => 1], " . $port];
        // Declared while the file loads, so a later item may call it.
        yield 'function declared in an invoked closure' => [$state . "'declare' => (function () { function psalmLaravelSetPort(object \$s): void { \$s->port = env('TOKEN'); } })(), 'init' => psalmLaravelSetPort(\$state), " . $port];
        yield 'method call' => ["<?php \$state = new ArrayObject(['port' => 8080]); return ['init' => \$state->offsetSet('port', env('TOKEN')), 'port' => \$state['port']];"];
        yield 'by-reference argument' => ["<?php \$list = [8080]; return ['init' => array_splice(\$list, 0, 1, [env('TOKEN')]), 'port' => \$list[0]];"];
    }

    #[Test]
    public function a_class_name_key_is_walked_like_a_string_key(): void
    {
        $index = ConfigSourceIndex::fromDirectories($this->directory([
            'telescope.php' => "<?php use Laravel\\Telescope\\Watchers; return ['watchers' => [Watchers\\CacheWatcher::class => env('W', true), 'x' => 1]];",
        ]));

        $type = ConfigValueReflector::reflect(['watchers' => ['Laravel\Telescope\Watchers\CacheWatcher' => 'false', 'x' => 1]], $index->find('telescope'));

        $this->assertSame("array{watchers: array{'Laravel\\\\Telescope\\\\Watchers\\\\CacheWatcher': bool|string, x: int}}", $type->getId());
    }

    #[Test]
    public function a_missing_directory_or_key_has_no_source(): void
    {
        $this->assertNull(ConfigSourceIndex::fromDirectories(null)->find('app.key'));
        $this->assertNull(ConfigSourceIndex::fromDirectories('/nonexistent/config')->find('app.key'));
        $this->assertNull(ConfigSourceIndex::fromDirectories($this->directory(['app.php' => '<?php return [];']))->find('app.key'));
    }

    /** The expression as source code; NameResolver turns a global `env()` into `\env()`. */
    private function code(?ConfigSource $source): ?string
    {
        return $source instanceof ConfigSource
            ? \ltrim((new Standard())->prettyPrintExpr($source->expression()), '\\')
            : null;
    }

    /** @param array<string, string> $files relative path => contents */
    private function directory(array $files): string
    {
        $directory = \sys_get_temp_dir() . '/psalm-laravel-config-' . \bin2hex(\random_bytes(6));
        $this->directories[] = $directory;

        foreach ($files as $path => $contents) {
            $file = $directory . '/' . $path;
            if (!\is_dir(\dirname($file))) {
                \mkdir(\dirname($file), 0777, true);
            }

            \file_put_contents($file, $contents);
        }

        return $directory;
    }
}
