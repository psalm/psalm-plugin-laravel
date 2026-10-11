<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\ComponentViewSeedHandler;
use Psalm\LaravelPlugin\Blade\UndeclaredVariableRegistry;
use Symfony\Component\Process\Process;

/**
 * #1827: `<blade reportUndeclaredVariables="true" />` leaves a contract template's undeclared reads
 * to Psalm's own UndefinedGlobalVariable / UndefinedVariable, unless something the run can see
 * still provides the name.
 */
#[CoversClass(UndeclaredVariableRegistry::class)]
#[CoversClass(ComponentViewSeedHandler::class)]
#[Group('subprocess')]
final class UndeclaredVariablesTest extends TestCase
{
    use AnalysesFixtureApp;

    private const FIXTURE = __DIR__ . '/Fixtures/UndeclaredVariables';

    /** @var list<string> */
    private const ARGUMENTS = ['-c', 'psalm.xml', '--no-cache', '--threads=1', '--no-progress', '--output-format=json'];

    /** @var list<string> */
    private const EXPECTED = [
        'components/profile.blade.php:3 UndefinedGlobalVariable $profileTypo',
        'typo.blade.php:3 UndefinedGlobalVariable $titel',
        'typo.blade.php:4 UndefinedVariable $insideArrow',
    ];

    /**
     * Everything else in the fixture is a decline or an allowance: a template without a contract,
     * a `View::share()` key and `$app`, a composer's and a creator's view, `@props`, a Livewire
     * attribute and a `livewire.*` view, a seeded class component's own data keys (untyped property,
     * `render()` key, named slot, `$componentName`), a class component view also `@include`d, and an
     * anonymous component view (with or without component markers, default, path, and namespace roots).
     */
    #[Test]
    public function reports_only_provably_undeclared_reads(): void
    {
        $this->assertSame(self::EXPECTED, $this->undeclaredIn($this->fixtureIssues(self::FIXTURE, self::ARGUMENTS)));
    }

    #[Test]
    public function the_flag_off_reports_nothing(): void
    {
        $arguments = ['-c', 'psalm-off.xml', '--no-cache', '--threads=1', '--no-progress', '--output-format=json'];

        $this->assertSame([], $this->undeclaredIn($this->fixtureIssues(self::FIXTURE, $arguments)));
    }

    /** A warm run skips the compiler; the omitted names come back from the manifest. */
    #[Test]
    public function a_warm_shadow_cache_reports_the_same(): void
    {
        $run = $this->analyzeFixtureSequence(self::FIXTURE, [self::ARGUMENTS, self::ARGUMENTS]);

        $this->assertSame(self::EXPECTED, $this->undeclaredIn($this->decodeIssues($run)));
    }

    /** A flag-off run in between must not leave its own entries for the next flag-on run to reuse. */
    #[Test]
    public function toggling_the_flag_off_and_back_on_reports_the_same(): void
    {
        $off = ['-c', 'psalm-off.xml', '--no-cache', '--threads=1', '--no-progress', '--output-format=json'];
        $run = $this->analyzeFixtureSequence(self::FIXTURE, [self::ARGUMENTS, $off, self::ARGUMENTS]);

        $this->assertSame(self::EXPECTED, $this->undeclaredIn($this->decodeIssues($run)));
    }

    /**
     * A slot named by an expression can supply any variable, and the collector cannot tell which
     * component it went to, so no seeded component view keeps its key allowance. Run on a copy:
     * one such slot anywhere changes every other case's component expectations.
     */
    #[Test]
    public function a_dynamic_slot_name_declines_seeded_component_views(): void
    {
        $copy = \sys_get_temp_dir() . '/psalm-undeclared-variables-' . \getmypid();
        self::removeTree($copy);
        self::copyTree(self::FIXTURE, $copy);

        try {
            $views = $copy . '/resources/views';
            \file_put_contents($views . '/dynamic-slot.blade.php', "@php(\$slotName = 'header')\n<x-profile>\n@slot(\$slotName) H @endslot\n</x-profile>\n");
            \file_put_contents($views . '/components/profile.blade.php', "{{ \$header }}\n", \FILE_APPEND);

            $process = new Process([\PHP_BINARY, \dirname(__DIR__, 3) . '/vendor/bin/psalm', ...self::ARGUMENTS], $copy);
            $process->setTimeout(300);
            $process->run();
            $run = ['output' => $process->getOutput(), 'errorOutput' => $process->getErrorOutput(), 'shadows' => ''];

            $this->assertSame(
                ['typo.blade.php:3 UndefinedGlobalVariable $titel', 'typo.blade.php:4 UndefinedVariable $insideArrow'],
                $this->undeclaredIn($this->decodeIssues($run)),
            );
        } finally {
            self::removeTree($copy);
        }
    }

    /**
     * @param list<array<string, mixed>> $issues
     *
     * @return list<string>
     */
    private function undeclaredIn(array $issues): array
    {
        $this->assertNotSame([], $issues, 'the fixture run reported nothing at all.');

        $found = [];

        foreach ($issues as $issue) {
            $type = (string) $issue['type'];

            if (\preg_match('/^(Possibly)?Undefined(Global)?Variable$/', $type) !== 1) {
                continue;
            }

            \preg_match('/\$\w+/', (string) $issue['message'], $name);
            $file = \str_replace('resources/views/', '', (string) $issue['file_name']);
            $found[] = $file . ':' . $issue['line_from'] . ' ' . $type . ' ' . ($name[0] ?? '?');
        }

        \sort($found);

        return $found;
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

    private static function removeTree(string $directory): void
    {
        if (!\is_dir($directory)) {
            return;
        }

        foreach (\array_diff(\scandir($directory) ?: [], ['.', '..']) as $entry) {
            \is_dir($directory . '/' . $entry) && !\is_link($directory . '/' . $entry)
                ? self::removeTree($directory . '/' . $entry)
                : \unlink($directory . '/' . $entry);
        }

        \rmdir($directory);
    }
}
