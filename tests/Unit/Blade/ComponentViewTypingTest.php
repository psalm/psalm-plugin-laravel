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
    use AnalysesFixtureApp;

    private const FIXTURE = __DIR__ . '/Fixtures/ComponentViews';

    /** @var list<string> */
    private const ARGUMENTS = ['-c', 'psalm.xml', '--no-cache', '--threads=1', '--no-progress', '--output-format=json'];

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function traces(): iterable
    {
        yield 'view(): props, attributes, componentName, methods; contract and slot names kept' => ['alert.blade.php', [
            '$attributes: Illuminate\View\ComponentAttributeBag',
            '$componentName: string',
            '$footer: mixed',
            '$format: Closure[impure]',
            '$isActive: Illuminate\View\InvokableComponentVariable',
            // The prelude keeps `@var mixed $items` (the template reassigns it from itself); the seed
            // lands after it.
            '$items: list<string>',
            '$label: non-empty-string',
            '$summary: Illuminate\View\InvokableComponentVariable',
            '$title: string',
            '$untyped: mixed',
        ]];
        yield 'view()->make(): a key render() passes stays mixed' => ['card.blade.php', ['$title: mixed', '$width: int']];
        yield "View::make(); a raw @var name stays the template's" => ['panel.blade.php', ['$heading: string', '$subtitle: mixed']];
        yield 'inherited render() returning a view name' => ['notice.blade.php', ['$message: string']];
        // Seeded once, on the prelude's sentinel: a later empty statement must not re-seed a reassigned name.
        yield '$this->view(); seeded once' => ['badge.blade.php', ["\$count: 'reassigned'", '$count: int']];
        yield 'no <x-sidebar> anywhere' => ['sidebar.blade.php', ['$heading: mixed']];
        yield 'two declaring classes' => ['banner.blade.php', ['$headline: mixed']];
        yield 'two concrete classes sharing one render()' => ['twin.blade.php', ['$tone: mixed']];
        yield 'userland $except' => ['hidden.blade.php', ['$secret: mixed']];
        yield 'another render() outside the shape names the view' => ['gallery.blade.php', ['$caption: mixed']];
        yield 'data() override' => ['overridden.blade.php', ['$label: mixed']];
        yield 'inherited $this->view() under a view() override' => ['chip.blade.php', ['$tone: mixed']];
        yield 'a namespace function named view()' => ['ticket.blade.php', ['$code: mixed']];
        yield 'a data argument that is not a literal array' => ['compacted.blade.php', ['$title: mixed']];
        yield 'a ->with() link after the view call' => ['withed.blade.php', ['$title: mixed']];
        yield 'named arguments' => ['named.blade.php', ['$title: mixed']];
        yield 'also rendered by @include' => ['included.blade.php', ['$title: mixed']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('traces')]
    #[Test]
    public function types_a_component_view_from_its_class(string $template, array $expected): void
    {
        $this->assertSame($expected, $this->tracesIn($this->fixtureIssues(self::FIXTURE, self::ARGUMENTS), $template));
    }

    /**
     * The seed is computed from class storage on every run, never written into the shadow: a warm
     * Psalm cache and an unchanged (fresh) shadow must still follow a changed property type.
     */
    #[Test]
    public function a_warm_cache_follows_a_changed_property_type(): void
    {
        $copy = \sys_get_temp_dir() . '/psalm-component-views-' . \getmypid();
        self::deleteTree($copy);
        self::copyTree(self::FIXTURE, $copy);

        try {
            \file_put_contents(
                $copy . '/psalm-cached.xml',
                \str_replace('<psalm', '<psalm cacheDirectory=".cache/psalm"', (string) \file_get_contents($copy . '/psalm.xml')),
            );
            $arguments = ['-c', 'psalm-cached.xml', '--threads=1', '--no-progress', '--output-format=json'];

            $this->assertSame(['$message: string'], $this->tracesIn($this->runPsalm($copy, $arguments), 'notice.blade.php'));
            $this->assertDirectoryExists($copy . '/.cache/psalm', 'the first run wrote no Psalm cache, so the second proves nothing.');

            $notice = $copy . '/app/View/Components/Notice.php';
            \file_put_contents($notice, \str_replace("public string \$message = 'Saved';", 'public int $message = 1;', (string) \file_get_contents($notice)));

            $this->assertSame(['$message: int'], $this->tracesIn($this->runPsalm($copy, $arguments), 'notice.blade.php'));
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
        $process->run();

        return $this->decodeIssues(['output' => $process->getOutput(), 'errorOutput' => $process->getErrorOutput(), 'shadows' => '']);
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
