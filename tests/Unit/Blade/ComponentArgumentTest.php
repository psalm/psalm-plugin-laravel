<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\ComponentArgumentHandler;

/**
 * #1936: a class component tag's arguments are checked against the component's constructor, the way
 * `Component::resolve()` passes them. Each `page.blade.php` line is one case; a line absent from the
 * expectations is a negative case (see the fixture).
 */
#[CoversClass(ComponentArgumentHandler::class)]
#[Group('subprocess')]
final class ComponentArgumentTest extends TestCase
{
    use AnalysesFixtureApp;

    private const FIXTURE = __DIR__ . '/Fixtures/ComponentArguments';

    /** @var list<string> */
    private const ARGUMENTS = ['-c', 'psalm.xml', '--no-cache', '--threads=1', '--no-progress', '--output-format=json'];

    #[Test]
    public function reports_wrong_and_missing_constructor_arguments_on_the_tag_line(): void
    {
        $alert = 'App\View\Components\Alert::__construct';

        $this->assertSame([
            "page.blade.php:1 InvalidArgument Argument \$count of {$alert} expects int, but 'many' provided",
            "page.blade.php:4 InvalidArgument Argument \$count of {$alert} expects int, but 'many' provided",
            "page.blade.php:5 TooFewArguments Too few arguments for {$alert} - expecting \$count to be passed",
            "page.blade.php:6 InvalidArgument Argument \$type of {$alert} expects string, but null provided",
            // A multi-line tag reports on its opening line.
            "page.blade.php:9 InvalidArgument Argument \$count of {$alert} expects int, but 'many' provided",
            // Inherited constructor.
            "page.blade.php:13 InvalidArgument Argument \$width of App\View\Components\Card::__construct expects int, but 'wide' provided",
            // `self` in the parent's constructor is the parent.
            "page.blade.php:15 InvalidArgument Argument \$previous of App\View\Components\Link::__construct expects App\View\Components\BaseLink|null, but 'back' provided",
            "page.blade.php:16 InvalidArgument Argument \$maxValue of App\View\Components\Meter::__construct expects int, but 'high' provided",
            "page.blade.php:21 TooFewArguments Too few arguments for App\View\Components\Legacy::__construct - expecting \$value to be passed",
            "page.blade.php:25 InvalidArgument Argument \$text of App\View\Components\Label::__construct expects string, but list{'x'} provided",
            // `@component(Alert::class, ...)` compiles to the same `resolve()` call.
            "page.blade.php:29 InvalidArgument Argument \$count of {$alert} expects int, but 'many' provided",
            // A docblock narrower than the native type is checked as Psalm checks it in PHP.
            "page.blade.php:38 InvalidArgument Argument \$tone of App\View\Components\Tone::__construct expects 'error'|'info', but 'warning' provided",
            // A component view: the enclosing `$attributes` may supply `$count`, so only the type is checked.
            "wrapper.blade.php:3 InvalidArgument Argument \$count of {$alert} expects int, but 'many' provided",
        ], $this->argumentIssues());
    }

    /**
     * @return list<string>
     */
    private function argumentIssues(): array
    {
        $issues = [];

        foreach ($this->fixtureIssues(self::FIXTURE, self::ARGUMENTS) as $issue) {
            $file = (string) $issue['file_name'];
            $type = (string) $issue['type'];

            if (!\str_ends_with($file, '.blade.php') || !\in_array($type, ['InvalidArgument', 'TooFewArguments'], true)) {
                continue;
            }

            $issues[] = \basename($file) . ':' . $issue['line_from'] . " {$type} " . $issue['message'];
        }

        \sort($issues, \SORT_NATURAL);

        return $issues;
    }
}
