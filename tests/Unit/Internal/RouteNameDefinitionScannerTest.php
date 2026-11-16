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
                Route::match(['get'], '/f', ['as' => 'match.slot', 'uses' => $action]);
                Route::view('/v', 'page')->name('view.verb');
                Route::redirect('/r', '/')->name('redirect.verb');
                Route::get('/g', ['as' => 'as.commented' /* why */, 'uses' => $action]);
                Route::name('static.direct')->get('/h', $action);
                Route::get('/n', $action)?->name('nullsafe.call');
                $router->get('/o', $action)->name('router.variable');
                $this->router->get('/p', $action)->name('this.router');
                \Illuminate\Support\Facades\Route::get('/q', $action)->name('fully.qualified');
            }
            PHP);

        $this->assertSame(
            ['single.quoted', 'double.quoted', 'spread.over.lines', 'as.single', 'as.double', 'match.slot', 'view.verb', 'redirect.verb', 'as.commented', 'static.direct', 'nullsafe.call', 'router.variable', 'this.router', 'fully.qualified'],
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
            Route::get('/k', $a)->name(b'binary.prefixed');
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

    /** Neither the prefix nor the bare leaf is a route name: Laravel registers only the composed one. */
    #[Test]
    public function declines_names_registered_under_a_group_prefix(): void
    {
        $this->write('routes/web.php', <<<'PHP'
            <?php
            Route::name('barcode.')->group(function () {
                Route::get('/', $a)->name('index');
            });
            Route::middleware('auth')->name('admin.')->prefix('admin')->group(function () {
                Route::get('/u', $a)->name('users');
                Route::group([], function () {
                    Route::get('/n', $a)->name('nested');
                });
            });
            Route::group(['as' => 'array.', 'prefix' => 'a'], function () {
                Route::get('/', $a)->name('array_leaf');
            });
            Route::name('chain.')->get('/c', $a)->name('chain_leaf');
            Route::get('/after', $a)->name('after.group');
            PHP);

        $this->assertSame(['after.group'], \array_keys(RouteNameDefinitionScanner::scan($this->root)));
    }

    /** A prefix needs no trailing dot, and need not be a literal: any name set before `group()` is one. */
    #[Test]
    public function declines_a_group_whatever_its_prefix_looks_like(): void
    {
        $this->write('routes/web.php', <<<'PHP'
            <?php
            Route::name('admin-')->group(fn () => Route::get('/a', $a)->name('dash_leaf'));
            Route::name($prefix)->group(fn () => Route::get('/b', $a)->name('variable_leaf'));
            Route::name('x' . $suffix)->group(fn () => Route::get('/c', $a)->name('concat_leaf'));
            Route::as('as-method')->group(fn () => Route::get('/d', $a)->name('as_method_leaf'));
            Route::group(['as' => $prefix], fn () => Route::get('/e', $a)->name('dynamic_as_leaf'));
            $router->name('router-')->group(function () use ($router) {
                $router->get('/f', $a)->name('router_leaf');
            });
            Route::prefix('plain')->middleware('auth')->group(fn () => Route::get('/g', $a)->name('unprefixed.leaf'));
            PHP);

        $this->assertSame(['unprefixed.leaf'], \array_keys(RouteNameDefinitionScanner::scan($this->root)));
    }

    /** Laravel concatenates names set in one chain, so two sources never spell out the final name. */
    #[Test]
    public function declines_a_chain_with_more_than_one_name_source(): void
    {
        $this->write('routes/web.php', <<<'PHP'
            <?php
            Route::name('a')->get('/x', $a)->name('b');
            Route::get('/y', ['as' => 'c', 'uses' => $a])->name('d');
            PHP);

        $this->assertSame([], RouteNameDefinitionScanner::scan($this->root));
    }

    /** `Route::view()` takes view data, not an action: its `'as'` key names nothing. */
    #[Test]
    public function reads_as_only_from_the_route_action_slot(): void
    {
        $this->write('routes/web.php', <<<'PHP'
            <?php
            Route::view('/p', 'page', ['as' => 'view.data']);
            Route::get('/a', $a, ['as' => 'get.third.argument']);
            Route::match(['get'], ['as' => 'match.second.argument']);
            Route::any('/b', ['as' => 'any.slot']);
            Route::get('/c', ['nested' => ['as' => 'too.deep']]);
            Route::redirect('/r', '/', 301);
            PHP);

        $this->assertSame(['any.slot'], \array_keys(RouteNameDefinitionScanner::scan($this->root)));
    }

    /** Named arguments and spreads cannot be mapped to a parameter position, so the chain is declined. */
    #[Test]
    public function declines_named_arguments_and_spreads(): void
    {
        $this->write('routes/web.php', <<<'PHP'
            <?php
            Route::get('/a', $a)->name(name: 'named.name');
            Route::get(uri: '/b', action: ['as' => 'named.get'])->name('also.named');
            Route::get(...$arguments)->name('spread.get');
            Route::match(methods: ['get'], uri: '/c', action: ['as' => 'named.match']);
            Route::group(attributes: ['as' => 'admin.'], routes: function () {
                Route::get('/d', $a)->name('named_group_leaf');
            });
            Route::middleware(...$middleware)->group(function () {
                Route::get('/e', $a)->name('spread_group_leaf');
            });
            Route::group($attributes, function () {
                Route::get('/f', $a)->name('variable_attributes_leaf');
            });
            PHP);

        $this->assertSame([], RouteNameDefinitionScanner::scan($this->root));
    }

    /** A registrar prefix is never a complete name: `resource` derives `admin-.posts.index` and the like. */
    #[Test]
    public function declines_resource_style_registrars_and_anything_but_one_route(): void
    {
        $this->write('routes/web.php', <<<'PHP'
            <?php
            Route::name('admin-')->resource('posts', PostController::class);
            Route::name('api-')->apiResource('users', UserController::class);
            Route::resource('p', C::class)->name('resource.name');
            Route::singleton('profile', C::class)->name('singleton.name');
            Route::name('only')->prefix('x');
            Route::get('/a', $a)->post('/b', $a)->name('two.verbs');
            Route::name('no.verb');
            Route::get('/ok', $a)->name('one.verb');
            PHP);

        $this->assertSame(['one.verb'], \array_keys(RouteNameDefinitionScanner::scan($this->root)));
    }

    /** `name()` and `'as'` outside a route-definition chain never register a route. */
    #[Test]
    public function ignores_names_that_have_no_route_provenance(): void
    {
        $this->write('routes/console.php', <<<'PHP'
            <?php
            Schedule::command('reports:run')->name('reports');
            $schedule->command('b')->name('scheduled');
            $builder->name('builder');
            $route = Route::get('/x', $a);
            $route->name('stored.registrar');
            $config = ['as' => 'config.entry', 'name' => 'x'];
            return ['as' => 'returned.array'];
            Cache::remember('k', 1, fn () => ['as' => 'arrow.array']);
            Route::get('/y', fn () => ['as' => 'response.array']);
            Route::get('/z', function () { return ['as' => 'closure.array']; });
            Route::get('/w', [Controller::class, 'a'])->middleware(['as' => 'middleware.array']);
            Route::get('/v', ['nested' => ['as' => 'too.deep']]);
            PHP);

        $this->assertSame([], RouteNameDefinitionScanner::scan($this->root));
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
