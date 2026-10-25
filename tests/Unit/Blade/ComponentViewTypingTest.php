<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\ComponentViewRegistry;
use Psalm\LaravelPlugin\Blade\ComponentViewSeedHandler;
use Symfony\Component\Process\Process;

/**
 * #1804 slice 1: a class component's own view gets the variables `Component::data()` hands it on
 * the `<x-…>` render path. Every template traces the names it reads; `mixed` is the prelude's
 * fallback, i.e. the registry declined.
 */
#[CoversClass(ComponentViewRegistry::class)]
#[CoversClass(ComponentViewSeedHandler::class)]
#[Group('subprocess')]
final class ComponentViewTypingTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/Fixtures/ComponentViews';

    /**
     * Both reports come from one throwaway copy of the fixture analysed twice with a warm Psalm cache
     * (see {@see self::warmCacheReports()}), so the whole class costs two Psalm boots.
     *
     * @var array{first: list<array<string, mixed>>, second: list<array<string, mixed>>, cacheWritten: bool}|null
     */
    private static ?array $reports = null;

    #[\Override]
    public static function tearDownAfterClass(): void
    {
        self::$reports = null;
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function traces(): iterable
    {
        yield 'view(): props, attributes, componentName, methods; contract and slot names kept' => ['alert.blade.php', [
            // #1856: a no-parameter method's wrapper carries its return type, so the call stays typed.
            '$active: bool',
            '$attributes: Illuminate\View\ComponentAttributeBag',
            '$componentName: string',
            '$footer: mixed',
            '$format: Closure[impure](string):string',
            '$isActive: Illuminate\View\InvokableComponentVariable<bool>',
            // The prelude keeps `@var mixed $items` (the template reassigns it from itself); the seed
            // lands after it.
            '$items: list<string>',
            '$label: non-empty-string',
            '$summary: Illuminate\View\InvokableComponentVariable<string>',
            '$title: string',
            '$untyped: mixed',
        ]];
        // #1855: a method with parameters is its own signature; a generic one is `mixed`.
        // #1856: `static` resolves to the class even when it is not final, `self` in a trait to the
        // using class.
        yield 'methods: parameters, static and a trait self on a non-final class' => ['toolbar.blade.php', [
            '$echo: mixed',
            '$f: App\View\Components\Toolbar',
            '$fresh: Illuminate\View\InvokableComponentVariable<App\View\Components\Toolbar>',
            '$m: App\View\Components\Toolbar',
            '$me: Illuminate\View\InvokableComponentVariable<App\View\Components\Toolbar>',
            '$pad: Closure[impure](string, int...):string',
            '$target: App\\View\\Components\\Toolbar',
            '$withTone: Closure[impure](string):App\\View\\Components\\Toolbar',
        ]];
        // #2043: `parent` names the class declaring the property, a trait's `self` the class using it.
        yield 'self and parent in property types' => ['owned.blade.php', [
            '$owner: Illuminate\View\Component',
            '$siblings: list<App\View\Components\OwnedBase>',
        ]];
        // A method's `public-properties-of<self>` keeps a raw `parent` inside its array: seeding it
        // unexpanded makes a call on `$snapshot()['owner']` throw instead of resolving.
        yield 'a method type Psalm expands only one level deep' => ['snapshot.blade.php', ['$named: Illuminate\View\Component&static']];
        // `View::gatherData()` renders every top-level Renderable before the template runs.
        yield 'Renderable and View properties arrive as the string they render to' => ['frame.blade.php', ['$content: string', '$inner: string']];
        yield 'view()->make(): a key render() passes stays mixed' => ['card.blade.php', ['$title: mixed', '$width: int']];
        yield "View::make(); a raw @var name stays the template's" => ['panel.blade.php', ['$heading: string', '$subtitle: mixed']];
        yield 'inherited render() returning a view name' => ['notice.blade.php', ['$message: string']];
        // Seeded once, on the prelude's sentinel: a later empty statement must not re-seed a reassigned name.
        yield '$this->view(); seeded once' => ['badge.blade.php', ["\$count: 'reassigned'", '$count: int']];
        // #1828: `data()` (and `render()`'s own data) hand the view its values before `@props`
        // falls back to a default, so a class's view never takes a type from a literal default.
        // The reset touches only what the prelude typed from a default: Blade's own names keep theirs.
        yield '@props defaults in a seeded view' => ['sized.blade.php', [
            '$errors: Illuminate\Support\ViewErrorBag',
            '$size: mixed',
            '$slot: Illuminate\View\ComponentSlot',
            '$tone: mixed',
        ]];
        // Declines: each row hits a different gate of the registry, and every one leaves the prelude's `mixed`.
        yield 'no <x-sidebar> anywhere; @props default still reset' => ['sidebar.blade.php', ['$heading: mixed', '$size: mixed']];
        yield 'two declaring classes' => ['banner.blade.php', ['$headline: mixed']];
        yield 'another render() outside the shape names the view' => ['gallery.blade.php', ['$caption: mixed']];
        yield 'data() override' => ['overridden.blade.php', ['$label: mixed']];
        yield 'inherited $this->view() under a view() override' => ['chip.blade.php', ['$tone: mixed']];
        yield 'a namespace function named view()' => ['ticket.blade.php', ['$code: mixed']];
        yield 'a data argument that is not a literal array' => ['compacted.blade.php', ['$title: mixed']];
        yield 'a ->with() link after the view call' => ['withed.blade.php', ['$title: mixed']];
        yield 'also rendered by @include' => ['included.blade.php', ['$title: mixed']];
        // `ViewName::normalize()` makes `components/linked` the view `components.linked`.
        yield 'also rendered by @include, spelled with a slash' => ['linked.blade.php', ['$title: mixed']];
        // A constructor that assigns `$this->except` may drop any name at run time: the registry declines.
        yield 'a constructor that assigns $except' => ['ctor-except.blade.php', ['$heading: mixed']];
        yield 'a returned string with three :: segments is inline Blade' => ['suffixed.blade.php', ['$title: mixed']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('traces')]
    #[Test]
    public function types_a_component_view_from_its_class(string $template, array $expected): void
    {
        $this->assertSame($expected, $this->tracesIn($this->warmCacheReports()['first'], $template));
    }

    /**
     * A seeded view that never writes `@props` or names `$attributes` still holds a nested tag's
     * compiled `isset($attributes)` / `instanceof` bookkeeping (card.blade.php nests one); with
     * `$attributes` seeded non-null, those guards are the compiler's, not the author's, and must stay
     * silent. A `@param-out self` must likewise reach toolbar.blade.php resolved: a literal `self`
     * out-type would make the by-ref variable a `self` no other signature accepts.
     */
    #[DataProvider('seededViews')]
    #[Test]
    public function a_seeded_view_reports_nothing_beyond_its_traces(string $template): void
    {
        $issues = $this->warmCacheReports()['first'];
        $reported = [];

        foreach ($issues as $issue) {
            if ($issue['type'] !== 'Trace' && \str_ends_with((string) $issue['file_name'], 'components/' . $template)) {
                $reported[] = $issue['type'] . ': ' . $issue['message'];
            }
        }

        $this->assertNotSame([], $this->tracesIn($issues, $template), 'the view was not typed, so the silence proves nothing.');
        $this->assertSame([], $reported);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function seededViews(): iterable
    {
        yield 'a nested tag in an unmarked view' => ['card.blade.php'];
        yield 'a method closure with @param-out self' => ['toolbar.blade.php'];
    }

    /**
     * The seed is computed from class storage on every run, never written into the shadow: a warm
     * Psalm cache and an unchanged (fresh) shadow must still follow a changed property type.
     */
    #[Test]
    public function a_warm_cache_follows_a_changed_property_type(): void
    {
        $reports = $this->warmCacheReports();

        $this->assertTrue($reports['cacheWritten'], 'the first run wrote no Psalm cache, so the second proves nothing.');
        $this->assertSame(['$message: string'], $this->tracesIn($reports['first'], 'notice.blade.php'));
        $this->assertSame(['$message: int'], $this->tracesIn($reports['second'], 'notice.blade.php'));
    }

    /**
     * Run 1 is the report every trace case asserts against, so those cases also prove a cache-enabled
     * run types the views as a cold one does. Run 2 follows a changed `Notice::$message`.
     *
     * @return array{first: list<array<string, mixed>>, second: list<array<string, mixed>>, cacheWritten: bool}
     */
    private function warmCacheReports(): array
    {
        if (self::$reports !== null) {
            return self::$reports;
        }

        $copy = \sys_get_temp_dir() . '/psalm-component-views-' . \getmypid();
        self::deleteTree($copy);
        self::copyTree(self::FIXTURE, $copy);

        try {
            \file_put_contents(
                $copy . '/psalm-cached.xml',
                \str_replace('<psalm', '<psalm cacheDirectory=".cache/psalm"', (string) \file_get_contents($copy . '/psalm.xml')),
            );
            $arguments = ['-c', 'psalm-cached.xml', '--threads=1', '--no-progress', '--output-format=json'];

            $first = $this->runPsalm($copy, $arguments);
            $cacheWritten = \is_dir($copy . '/.cache/psalm');

            $notice = $copy . '/app/View/Components/Notice.php';
            \file_put_contents($notice, \str_replace("public string \$message = 'Saved';", 'public int $message = 1;', (string) \file_get_contents($notice)));

            return self::$reports = ['first' => $first, 'second' => $this->runPsalm($copy, $arguments), 'cacheWritten' => $cacheWritten];
        } finally {
            self::deleteTree($copy);
        }
    }

    /**
     * @param list<array<string, mixed>> $issues
     *
     * @return list<string>
     */
    private function tracesIn(array $issues, string $template): array
    {
        $this->assertNotSame([], $issues, 'the fixture run reported nothing at all.');

        $traces = [];

        foreach ($issues as $issue) {
            if ($issue['type'] === 'Trace' && \str_ends_with((string) $issue['file_name'], 'components/' . $template)) {
                $traces[] = (string) $issue['message'];
            }
        }

        \sort($traces);

        return $traces;
    }

    /**
     * @param list<string> $arguments
     *
     * @return list<array<string, mixed>>
     */
    private function runPsalm(string $directory, array $arguments): array
    {
        $process = new Process([\PHP_BINARY, \dirname(__DIR__, 3) . '/vendor/bin/psalm', ...$arguments], $directory);
        $process->setTimeout(300);
        // Not mustRun(): the fixture reports issues on purpose.
        $process->run();

        $decoded = \json_decode($process->getOutput(), true);
        $this->assertIsArray($decoded, "Psalm did not emit a JSON report.\n{$process->getOutput()}\n{$process->getErrorOutput()}");

        return \array_values(\array_filter($decoded, \is_array(...)));
    }

    private static function copyTree(string $from, string $to): void
    {
        \mkdir($to, 0o777, true);

        foreach (\array_diff(\scandir($from) ?: [], ['.', '..', '.cache']) as $entry) {
            \is_dir($from . '/' . $entry)
                ? self::copyTree($from . '/' . $entry, $to . '/' . $entry)
                : \copy($from . '/' . $entry, $to . '/' . $entry);
        }
    }

    private static function deleteTree(string $directory): void
    {
        if (!\is_dir($directory)) {
            return;
        }

        foreach (\array_diff(\scandir($directory) ?: [], ['.', '..']) as $entry) {
            \is_dir($directory . '/' . $entry) && !\is_link($directory . '/' . $entry)
                ? self::deleteTree($directory . '/' . $entry)
                : \unlink($directory . '/' . $entry);
        }

        \rmdir($directory);
    }
}
