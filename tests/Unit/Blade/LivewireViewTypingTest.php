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
use Psalm\LaravelPlugin\Blade\ShadowIssueRelocator;
use Symfony\Component\Process\Process;

/**
 * #1935: a Livewire component's view gets `$this` and the component's public properties. Every
 * template traces the names it reads; `mixed` is the prelude's fallback, i.e. the registry declined.
 */
#[CoversClass(ComponentViewRegistry::class)]
#[CoversClass(ComponentViewSeedHandler::class)]
#[CoversClass(ShadowIssueRelocator::class)]
#[Group('subprocess')]
final class LivewireViewTypingTest extends TestCase
{
    use AnalysesFixtureApp;

    private const FIXTURE = __DIR__ . '/Fixtures/LivewireViews';

    /** @var list<string> */
    private const ARGUMENTS = ['-c', 'psalm.xml', '--no-cache', '--threads=1', '--no-progress', '--output-format=json'];

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function traces(): iterable
    {
        yield 'public properties and $this; layout macros peeled' => ['show-post.blade.php', [
            // The property wins a key render() also passes: Livewire merges the properties last.
            '$count: int',
            // render()'s own data values are not typed.
            '$extra: mixed',
            '$hidden: mixed',
            '$instances: mixed',
            // Declared by Livewire's own base class.
            '$owned: mixed',
            '$post: App\Models\Post',
            '$saved: bool',
            '$secret: mixed',
            '$tags: list<string>',
            '$this: App\Livewire\ShowPost',
            '$untyped: mixed',
        ]];
        yield 'render() inherited from an abstract base' => ['listing.blade.php', [
            '$base: bool',
            '$page: int',
            '$this: App\Livewire\Listing',
        ]];
        yield 'non-literal data and a ->with() the properties override' => ['compacted.blade.php', [
            '$this: App\Livewire\Compacted',
            '$title: string',
        ]];
        // Nothing binds the declaring class's template here, so its property is not guessed.
        yield 'a property of a generic declaring class' => ['generic.blade.php', [
            '$item: mixed',
            '$page: int',
            '$this: App\Livewire\GenericPost',
        ]];
        // A composer runs after Livewire merges the properties, so the key it passes wins.
        yield 'a key a readable composer passes' => ['composed.blade.php', [
            '$count: int',
            '$this: App\Livewire\Composed',
            '$title: mixed',
        ]];
        yield 'an unreadable composer: no property is known' => ['composed-closure.blade.php', [
            '$page: mixed',
            '$this: App\Livewire\ComposedClosure',
        ]];
        // Lazy loading renders a placeholder() view with that component's own properties.
        yield "another component's placeholder() view" => ['placeheld.blade.php', ['$this: mixed', '$title: mixed']];
        yield 'two Livewire classes' => ['twin.blade.php', ['$this: mixed', '$tone: mixed']];
        yield 'a Livewire class and a Blade component' => ['shared.blade.php', ['$this: mixed', '$title: mixed']];
        yield 'also rendered by @include' => ['included.blade.php', ['$this: mixed', '$title: mixed']];
        yield 'another render() outside the shape names the view' => ['rivaled.blade.php', ['$this: mixed', '$title: mixed']];
        yield 'Volt component' => ['volt-page.blade.php', ['$this: mixed', '$title: mixed']];
        // A Livewire 4 multi-file component's class: Psalm gives an anonymous class no `location`.
        yield 'anonymous class' => ['anon.blade.php', ['$label: mixed', '$this: mixed']];
        yield 'no render()' => ['conventional.blade.php', ['$name: mixed', '$this: mixed']];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('traces')]
    #[Test]
    public function types_a_livewire_view_from_its_class(string $template, array $expected): void
    {
        $this->assertSame($expected, $this->tracesIn('livewire/' . $template));
    }

    /**
     * Unbound, `$this` stays the prelude's `mixed`; a template that types it itself keeps every
     * finding on it, magic or not.
     */
    #[Test]
    public function a_plain_view_keeps_this_mixed(): void
    {
        $this->assertSame(['$probe: mixed'], $this->tracesIn('views/plain.blade.php'));
        $this->assertSame(
            ['UndefinedThisPropertyFetch @3: Instance property App\Livewire\ShowPost::$total is not defined'],
            $this->reportedIn($this->fixtureIssues(self::FIXTURE, self::ARGUMENTS), 'views/plain.blade.php'),
        );
    }

    /**
     * Livewire binds the view's closure to the component with the component's own scope, so a
     * protected method is reachable, and a declared method's signature is checked. `__get()` and
     * `__call()` answer any other `$this->name`, so only another receiver's magic member reports.
     */
    #[Test]
    public function reports_against_the_component_class(): void
    {
        $this->assertSame([
            'UndefinedMethod @2: Method App\Models\Post::typo does not exist',
            "InvalidScalarArgument @7: Argument 1 of App\\Livewire\\ShowPost::rename expects int, but 'x' provided",
            'UndefinedMagicPropertyFetch @9: Magic instance property App\Livewire\ShowPost::$total is not defined',
            'UndefinedMagicMethod @9: Magic method App\Livewire\ShowPost::nope does not exist',
            // `$this` inside a class the template declares is that class, not the component.
            'UndefinedThisPropertyFetch @10: Instance property LocalProbe::$missing is not defined',
        ], $this->reportedIn($this->fixtureIssues(self::FIXTURE, self::ARGUMENTS), 'livewire/show-post.blade.php'));
    }

    /**
     * Without `__get()`/`__call()` on the class, nothing answers an undefined `$this` member.
     */
    #[Test]
    public function a_class_without_magic_keeps_reporting_this_members(): void
    {
        $copy = \sys_get_temp_dir() . '/psalm-livewire-views-' . \getmypid();
        self::deleteTree($copy);
        self::copyTree(self::FIXTURE, $copy);

        try {
            $base = $copy . '/livewire/Component.php';
            $source = (string) \file_get_contents($base);
            \file_put_contents($base, \substr($source, 0, (int) \strpos($source, '    public function __get(')) . "}\n");

            $process = new Process([\PHP_BINARY, \dirname(__DIR__, 3) . '/vendor/bin/psalm', ...self::ARGUMENTS], $copy);
            $process->setTimeout(300);
            $process->run();
            $issues = $this->decodeIssues(['output' => $process->getOutput(), 'errorOutput' => $process->getErrorOutput(), 'shadows' => '']);

            $reported = $this->reportedIn($issues, 'livewire/show-post.blade.php');
            $this->assertContains('UndefinedMethod @3: Method App\Livewire\ShowPost::nope does not exist', $reported);
            $this->assertContains('UndefinedThisPropertyFetch @6: Instance property App\Livewire\ShowPost::$total is not defined', $reported);
        } finally {
            self::deleteTree($copy);
        }
    }

    /**
     * @param list<array<string, mixed>> $issues
     *
     * @return list<string>
     */
    private function reportedIn(array $issues, string $template): array
    {
        $reported = [];

        foreach ($issues as $issue) {
            if ($issue['type'] !== 'Trace' && \str_ends_with((string) $issue['file_name'], $template)) {
                $reported[] = $issue['type'] . ' @' . $issue['line_from'] . ': ' . $issue['message'];
            }
        }

        return $reported;
    }

    /**
     * @return list<string>
     */
    private function tracesIn(string $template): array
    {
        $issues = $this->fixtureIssues(self::FIXTURE, self::ARGUMENTS);
        $this->assertNotSame([], $issues, 'the fixture run reported nothing at all.');

        $traces = [];

        foreach ($issues as $issue) {
            if ($issue['type'] === 'Trace' && \str_ends_with((string) $issue['file_name'], $template)) {
                $traces[] = (string) $issue['message'];
            }
        }

        \sort($traces);

        return $traces;
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
