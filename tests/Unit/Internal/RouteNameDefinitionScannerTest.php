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
                Route::get('/a', fn () => 1)->name('single.quoted');
                Route::get('/b', fn () => 1)->name("double.quoted");
                Route::get('/c', fn () => 1)
                    ->name(
                        'spread.over.lines',
                    );
                Route::get('/d', ['as' => 'as.single', 'uses' => $action]);
                Route::get('/e', ["as" => "as.double", 'uses' => $action]);
                Route::match(['get'], '/f', ['as' => 'match.slot', 'uses' => $action]);
                Route::view('/v', 'page')->name('view.verb');
                Route::redirect('/r', '/')->name('redirect.verb');
                Route::get('/g', ['as' => 'as.commented' /* why */, 'uses' => $action]);
                Route::name('static.direct')->get('/h', fn () => 1);
                Route::get('/n', fn () => 1)?->name('nullsafe.call');
                $router->get('/o', fn () => 1)->name('router.variable');
                $this->router->get('/p', fn () => 1)->name('this.router');
                \Illuminate\Support\Facades\Route::get('/q', fn () => 1)->name('fully.qualified');
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
        $this->write('packages/blog/routes/web.php', "<?php\nRoute::get('/', fn () => 1)->name('blog.index');\n");
        $this->write('app/Providers/RouteServiceProvider.php', "<?php\nRoute::get('/', fn () => 1)->name('provider.defined');\n");

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
            Route::get('/a', fn () => 1)->name($variable);
            Route::get('/b', fn () => 1)->name('prefix.' . $suffix);
            Route::get('/c', fn () => 1)->name("interpolated.{$suffix}");
            Route::get('/d', fn () => 1)->name("interpolated.$suffix");
            Route::get('/e', fn () => 1)->name(RouteName::Index->value);
            Route::get('/f', fn () => 1)->name('escaped\'quote');
            Route::get('/g', fn () => 1)->name('');
            Route::get('/h', ['as' => 'orders' . '.index', 'uses' => $a]);
            Route::get('/i', ['as' => $prefix . 'x', 'uses' => $a]);
            Route::get('/j', fn () => 1)->name('first' . '.second');
            Route::get('/k', fn () => 1)->name(b'binary.prefixed');
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
            // Route::get('/a', fn () => 1)->name('commented.out');
            /* Route::get('/b', fn () => 1)->name('block.comment'); */
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
                Route::get('/', fn () => 1)->name('index');
            });
            Route::middleware('auth')->name('admin.')->prefix('admin')->group(function () {
                Route::get('/u', fn () => 1)->name('users');
                Route::group([], function () {
                    Route::get('/n', fn () => 1)->name('nested');
                });
            });
            Route::group(['as' => 'array.', 'prefix' => 'a'], function () {
                Route::get('/', fn () => 1)->name('array_leaf');
            });
            Route::name('chain.')->get('/c', fn () => 1)->name('chain_leaf');
            Route::get('/after', fn () => 1)->name('after.group');
            PHP);

        $this->assertSame(['after.group'], \array_keys(RouteNameDefinitionScanner::scan($this->root)));
    }

    /** A prefix needs no trailing dot, and need not be a literal: any name set before `group()` is one. */
    #[Test]
    public function declines_a_group_whatever_its_prefix_looks_like(): void
    {
        $this->write('routes/web.php', <<<'PHP'
            <?php
            Route::name('admin-')->group(fn () => Route::get('/a', fn () => 1)->name('dash_leaf'));
            Route::name($prefix)->group(fn () => Route::get('/b', fn () => 1)->name('variable_leaf'));
            Route::name('x' . $suffix)->group(fn () => Route::get('/c', fn () => 1)->name('concat_leaf'));
            Route::as('as-method')->group(fn () => Route::get('/d', fn () => 1)->name('as_method_leaf'));
            Route::group(['as' => $prefix], fn () => Route::get('/e', fn () => 1)->name('dynamic_as_leaf'));
            $router->name('router-')->group(function () use ($router) {
                $router->get('/f', fn () => 1)->name('router_leaf');
            });
            Route::prefix('plain')->middleware('auth')->group(fn () => Route::get('/g', fn () => 1)->name('unprefixed.leaf'));
            PHP);

        $this->assertSame(['unprefixed.leaf'], \array_keys(RouteNameDefinitionScanner::scan($this->root)));
    }

    /** Laravel concatenates names set in one chain, so two sources never spell out the final name. */
    #[Test]
    public function declines_a_chain_with_more_than_one_name_source(): void
    {
        $this->write('routes/web.php', <<<'PHP'
            <?php
            Route::name('a')->get('/x', fn () => 1)->name('b');
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
            Route::get('/a', fn () => 1)->name(name: 'named.name');
            Route::get(uri: '/b', action: ['as' => 'named.get'])->name('also.named');
            Route::get(...$arguments)->name('spread.get');
            Route::match(methods: ['get'], uri: '/c', action: ['as' => 'named.match']);
            Route::group(attributes: ['as' => 'admin.'], routes: function () {
                Route::get('/d', fn () => 1)->name('named_group_leaf');
            });
            Route::middleware(...$middleware)->group(function () {
                Route::get('/e', fn () => 1)->name('spread_group_leaf');
            });
            Route::group($attributes, function () {
                Route::get('/f', fn () => 1)->name('variable_attributes_leaf');
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
            Route::get('/a', fn () => 1)->post('/b', fn () => 1)->name('two.verbs');
            Route::name('no.verb');
            Route::get('/ok', fn () => 1)->name('one.verb');
            PHP);

        $this->assertSame(['one.verb'], \array_keys(RouteNameDefinitionScanner::scan($this->root)));
    }

    /** Every method is on a list verified against Laravel as leaving the name alone; any other one declines. */
    #[Test]
    public function declines_a_chain_with_a_method_not_known_to_leave_the_name_alone(): void
    {
        $this->write('routes/web.php', <<<'PHP'
            <?php
            Route::middleware('web')->attribute('as', 'admin.')->get('/a', fn () => 1)->name('attribute_leaf');
            Route::get('/b', fn () => 1)->name('old')->setAction(['as' => 'actual']);
            Route::get('/c', fn () => 1)->uses('C@m')->name('uses_leaf');
            Route::get('/d', fn () => 1)->when($flag, fn ($r) => $r->name('x'))->name('conditionable_leaf');
            Route::get('/e', fn () => 1)->customMacro()->name('macro_leaf');
            Route::middleware('web')->attribute('as', 'admin.')->group(function () {
                Route::get('/f', fn () => 1)->name('attribute_group_leaf');
            });
            Route::someMacro()->group(function () {
                Route::get('/g', fn () => 1)->name('macro_group_leaf');
            });
            PHP);

        $this->assertSame([], RouteNameDefinitionScanner::scan($this->root));
    }

    #[Test]
    public function accepts_the_methods_that_leave_the_name_alone(): void
    {
        $this->write('routes/web.php', <<<'PHP'
            <?php
            Route::middleware('auth')->prefix('x')->domain('d')->controller(C::class)->namespace('N')
                ->get('/a', 'method')->where('id', '[0-9]+')->whereNumber('id')->withoutMiddleware('z')
                ->can('view')->scopeBindings()->withTrashed()->missing(fn () => 1)->block(1, 1)->defaults('k', 'v')
                ->metadata(['m' => 1])->name('all.safe');
            Route::middleware('auth')->group(function () {
                Route::get('/b', fn () => 1)->name('safe.group');
            });
            PHP);

        $this->assertEqualsCanonicalizing(['all.safe', 'safe.group'], \array_keys(RouteNameDefinitionScanner::scan($this->root)));
    }

    /** Group attributes must be a literal array of literal keys, else the prefix is unknown and the group is declined. */
    #[Test]
    public function declines_a_group_whose_attributes_are_not_a_closed_literal_array(): void
    {
        $this->write('routes/web.php', <<<'PHP'
            <?php
            Route::group(array_merge(['as' => 'admin.']), function () {
                Route::get('/a', fn () => 1)->name('merged_leaf');
            });
            Route::group((['as' => 'admin.']), function () {
                Route::get('/b', fn () => 1)->name('parenthesized_leaf');
            });
            Route::group([...$shared, 'prefix' => 'x'], function () {
                Route::get('/c', fn () => 1)->name('spread_leaf');
            });
            Route::group([$key => 'admin.'], function () {
                Route::get('/d', fn () => 1)->name('computed_key_leaf');
            });
            Route::group(['prefix' => 'x', 'admin.'], function () {
                Route::get('/e', fn () => 1)->name('keyless_leaf');
            });
            Route::group(['prefix' => 'x'] + $more, function () {
                Route::get('/f', fn () => 1)->name('union_leaf');
            });
            Route::group(['\x61s' => 'admin.'], function () {
                Route::get('/g', fn () => 1)->name('escaped_key_leaf');
            });
            Route::group(['prefix' => 'ok', 'middleware' => ['web']], function () {
                Route::get('/h', fn () => 1)->name('closed_literal_leaf');
            });
            Route::group(array('prefix' => 'ok'), function () {
                Route::get('/i', fn () => 1)->name('array_function_leaf');
            });
            PHP);

        $this->assertEqualsCanonicalizing(['closed_literal_leaf', 'array_function_leaf'], \array_keys(RouteNameDefinitionScanner::scan($this->root)));
    }

    /** An action that could carry its own `'as'` without the scanner seeing it is unknown, so nothing is recorded. */
    #[Test]
    public function declines_a_route_action_that_is_not_readable(): void
    {
        $this->write('routes/web.php', <<<'PHP'
            <?php
            Route::get('/a', ['uses' => fn () => 1, 'as' => 'old', ...['as' => 'actual']]);
            Route::get('/b', ['uses' => fn () => 1, $key => 'x'])->name('computed_key');
            Route::get('/c', $action)->name('variable_action');
            Route::get('/d', buildAction())->name('call_action');
            Route::get('/e', [$controller, 'method'])->name('object_callable');
            Route::get('/f', ['uses' => 'C@m'] + $extra)->name('union_action');
            Route::get('/g', [...$parts])->name('spread_callable');
            Route::fallback($action)->name('fallback_variable');
            PHP);

        $this->assertSame([], RouteNameDefinitionScanner::scan($this->root));
    }

    #[Test]
    public function accepts_the_route_actions_it_can_read(): void
    {
        $this->write('routes/web.php', <<<'PHP'
            <?php
            Route::get('/a', function () { return 1; })->name('closure');
            Route::get('/b', static fn () => 1)->name('static.arrow');
            Route::get('/c', 'Controller@method')->name('string');
            Route::get('/d', Controller::class)->name('invokable');
            Route::get('/e', [Controller::class, 'method'])->name('callable.array');
            Route::get('/f', ['uses' => 'C@m', 'middleware' => ['web']])->name('literal.keys');
            Route::get('/g')->name('no.action');
            Route::fallback(fn () => 1)->name('fallback.closure');
            PHP);

        $this->assertEqualsCanonicalizing(
            ['closure', 'static.arrow', 'string', 'invokable', 'callable.array', 'literal.keys', 'no.action', 'fallback.closure'],
            \array_keys(RouteNameDefinitionScanner::scan($this->root)),
        );
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
            $route = Route::get('/x', fn () => 1);
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
        $this->write('node_modules/pkg/routes.php', "<?php\nRoute::get('/', fn () => 1)->name('node.modules');\n");
        $this->write('storage/framework/cache.php', "<?php\nRoute::get('/', fn () => 1)->name('storage.cache');\n");
        $this->write('tests/Feature/RouteTest.php', "<?php\nRoute::get('/', fn () => 1)->name('test.only');\n");
        $this->write('routes/web.php', "<?php\nRoute::get('/', fn () => 1)->name('real.route');\n");

        $this->assertSame(['real.route'], \array_keys(RouteNameDefinitionScanner::scan($this->root)));
    }

    #[Test]
    public function skips_files_the_ignore_callback_rejects(): void
    {
        $this->write('legacy/routes.php', "<?php\nRoute::get('/', fn () => 1)->name('legacy.route');\n");
        $this->write('routes/web.php', "<?php\nRoute::get('/', fn () => 1)->name('current.route');\n");

        $names = RouteNameDefinitionScanner::scan($this->root, static fn(string $path): bool => \str_contains($path, '/legacy/'));

        $this->assertSame(['current.route'], \array_keys($names));
    }

    #[Test]
    public function does_not_enter_directories_the_ignore_callback_rejects(): void
    {
        $this->write('legacy/routes.php', "<?php\nRoute::get('/', fn () => 1)->name('legacy.route');\n");
        $this->write('routes/web.php', "<?php\nRoute::get('/', fn () => 1)->name('current.route');\n");

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
