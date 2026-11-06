<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Internal;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Internal\RouteNameDefinitionScanner;

#[CoversClass(RouteNameDefinitionScanner::class)]
final class RouteNameDefinitionScannerTest extends TestCase
{
    private string $root;

    #[\Override]
    protected function setUp(): void
    {
        $root = \realpath(\sys_get_temp_dir()) . '/psalm-route-names-' . \bin2hex(\random_bytes(8));
        \mkdir($root, 0o777, true);
        $this->root = $root;
    }

    #[\Override]
    protected function tearDown(): void
    {
        $this->removeTree($this->root);
    }

    #[Test]
    public function collects_literal_names_from_the_fluent_and_route_action_forms(): void
    {
        $this->write('routes/web.php', <<<'PHP'
            <?php
            if (config('feature.enabled')) {
                Route::get('/a', $action)->name('single.quoted');
                Route::get('/b', $action)->name("double.quoted");
                Route::get('/c', $action)
                    ->name(
                        'spread.over.lines',
                    );
                Route::get('/d', ['as' => 'as.single', 'uses' => $action]);
                Route::get('/e', ["as" => "as.double", 'uses' => $action]);
                Route::get('/f', $action)->name(name: 'named.argument');
                Route::get('/g', ['as' => 'as.commented' /* why */, 'uses' => $action]);
            }
            PHP);

        $this->assertSame(
            ['single.quoted', 'double.quoted', 'spread.over.lines', 'as.single', 'as.double', 'named.argument', 'as.commented'],
            \array_keys(RouteNameDefinitionScanner::scan($this->root)),
        );
    }

    #[Test]
    public function finds_route_files_outside_any_source_directory(): void
    {
        $this->write('packages/blog/routes/web.php', "<?php\nRoute::get('/', \$a)->name('blog.index');\n");
        $this->write('app/Providers/RouteServiceProvider.php', "<?php\nRoute::get('/', \$a)->name('provider.defined');\n");

        $this->assertEqualsCanonicalizing(
            ['blog.index', 'provider.defined'],
            \array_keys(RouteNameDefinitionScanner::scan($this->root)),
        );
    }

    #[Test]
    public function skips_names_that_are_not_one_literal(): void
    {
        $this->write('routes/web.php', <<<'PHP'
            <?php
            Route::get('/a', $a)->name($variable);
            Route::get('/b', $a)->name('prefix.' . $suffix);
            Route::get('/c', $a)->name("interpolated.{$suffix}");
            Route::get('/d', $a)->name("interpolated.$suffix");
            Route::get('/e', $a)->name(RouteName::Index->value);
            Route::get('/f', $a)->name('escaped\'quote');
            Route::get('/g', $a)->name('');
            Route::get('/h', ['as' => 'orders' . '.index', 'uses' => $a]);
            Route::get('/i', ['as' => $prefix . 'x', 'uses' => $a]);
            Route::get('/j', $a)->name('first' . '.second');
            $user->first_name('Not a route');
            PHP);

        $this->assertSame([], RouteNameDefinitionScanner::scan($this->root));
    }

    /** The lexer, not a text match, decides: none of these is a route definition. */
    #[Test]
    public function ignores_comments_strings_and_heredocs(): void
    {
        $this->write('routes/web.php', <<<'PHP'
            <?php
            // Route::get('/a', $a)->name('commented.out');
            /* Route::get('/b', $a)->name('block.comment'); */
            $help = "->name('inside.string')";
            $text = <<<'TXT'
            ->name('inside.nowdoc')
            TXT;
            $map = ['not\'as' => 'escaped.key', 'as' => 'a' . '.b'];
            PHP);

        $this->assertSame([], RouteNameDefinitionScanner::scan($this->root));
    }

    /** The leaf of a group-prefixed definition is found, the composed name is not: a documented limitation. */
    #[Test]
    public function does_not_compose_group_prefixes(): void
    {
        $this->write('routes/web.php', <<<'PHP'
            <?php
            Route::name('barcode.')->group(function () {
                Route::get('/', $a)->name('index');
            });
            PHP);

        $names = RouteNameDefinitionScanner::scan($this->root);

        $this->assertArrayHasKey('index', $names);
        $this->assertArrayNotHasKey('barcode.index', $names);
    }

    #[Test]
    public function skips_blade_templates_and_dependency_or_test_directories(): void
    {
        $this->write('resources/views/page.blade.php', "<?php ->name('blade.template') ?>\n");
        $this->write('node_modules/pkg/routes.php', "<?php\nRoute::get('/', \$a)->name('node.modules');\n");
        $this->write('storage/framework/cache.php', "<?php\nRoute::get('/', \$a)->name('storage.cache');\n");
        $this->write('tests/Feature/RouteTest.php', "<?php\nRoute::get('/', \$a)->name('test.only');\n");
        $this->write('routes/web.php', "<?php\nRoute::get('/', \$a)->name('real.route');\n");

        $this->assertSame(['real.route'], \array_keys(RouteNameDefinitionScanner::scan($this->root)));
    }

    #[Test]
    public function skips_files_the_ignore_callback_rejects(): void
    {
        $this->write('legacy/routes.php', "<?php\nRoute::get('/', \$a)->name('legacy.route');\n");
        $this->write('routes/web.php', "<?php\nRoute::get('/', \$a)->name('current.route');\n");

        $names = RouteNameDefinitionScanner::scan($this->root, static fn(string $path): bool => \str_contains($path, '/legacy/'));

        $this->assertSame(['current.route'], \array_keys($names));
    }

    #[Test]
    public function does_not_enter_directories_the_ignore_callback_rejects(): void
    {
        $this->write('legacy/routes.php', "<?php\nRoute::get('/', \$a)->name('legacy.route');\n");
        $this->write('routes/web.php', "<?php\nRoute::get('/', \$a)->name('current.route');\n");

        $visitedFiles = [];
        $names = RouteNameDefinitionScanner::scan($this->root, static function (string $path) use (&$visitedFiles): bool {
            if (\str_ends_with($path, '.php')) {
                $visitedFiles[] = \basename($path);
            }

            return \str_ends_with($path, '/legacy/');
        });

        $this->assertSame(['current.route'], \array_keys($names));
        $this->assertSame(['web.php'], $visitedFiles, 'the ignored directory must be pruned, not walked');
    }

    #[Test]
    public function returns_nothing_for_a_missing_root(): void
    {
        $this->assertSame([], RouteNameDefinitionScanner::scan($this->root . '/does-not-exist'));
    }

    private function write(string $relativePath, string $contents): void
    {
        $path = $this->root . '/' . $relativePath;
        \mkdir(\dirname($path), 0o777, true);
        \file_put_contents($path, $contents);
    }

    private function removeTree(string $path): void
    {
        if (!\is_dir($path)) {
            return;
        }

        foreach (\array_diff(\scandir($path) ?: [], ['.', '..']) as $entry) {
            $child = $path . '/' . $entry;
            \is_dir($child) && !\is_link($child) ? $this->removeTree($child) : \unlink($child);
        }

        \rmdir($path);
    }
}
