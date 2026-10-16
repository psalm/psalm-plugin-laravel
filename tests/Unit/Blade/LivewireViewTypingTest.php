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

/**
 * #1935: a Livewire component's view gets `$this` and the component's public properties. Every
 * template traces the names it reads; `mixed` is the prelude's fallback, i.e. the registry declined.
 */
#[CoversClass(ComponentViewRegistry::class)]
#[CoversClass(ComponentViewSeedHandler::class)]
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

    #[Test]
    public function a_plain_view_keeps_this_mixed(): void
    {
        $this->assertSame(['$probe: mixed'], $this->tracesIn('views/plain.blade.php'));
    }

    /**
     * Livewire binds the view's closure to the component with the component's own scope, so a
     * protected method is reachable; an undefined method and a typo on a typed property are not.
     */
    #[Test]
    public function reports_against_the_component_class(): void
    {
        $reported = [];

        foreach ($this->fixtureIssues(self::FIXTURE, self::ARGUMENTS) as $issue) {
            if ($issue['type'] !== 'Trace' && \str_ends_with((string) $issue['file_name'], 'livewire/show-post.blade.php')) {
                $reported[] = $issue['type'] . ' @' . $issue['line_from'] . ': ' . $issue['message'];
            }
        }

        $this->assertSame([
            'UndefinedMethod @2: Method App\Models\Post::typo does not exist',
            'UndefinedMethod @3: Method App\Livewire\ShowPost::nope does not exist',
        ], $reported);
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
}
