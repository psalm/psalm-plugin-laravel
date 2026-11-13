<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Psalter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Rules\OctaneIncompatibleBindingHandler;
use Psalm\LaravelPlugin\Plugin;
use Symfony\Component\Process\Process;
use Tests\Psalm\LaravelPlugin\Unit\Concerns\CopiesFixtureDirectories;

/**
 * End-to-end guard for the OctaneIncompatibleBinding Psalter fix: one real `vendor/bin/psalter` run over the
 * PsalterApp fixture, whose rewritten `cases/*.php` are compared with their `expected/*.php` copies (the input
 * itself for a case that must stay unchanged). A case is two files; the data provider discovers them.
 */
#[CoversClass(Plugin::class)]
#[CoversClass(OctaneIncompatibleBindingHandler::class)]
#[Group('subprocess')]
final class PsalterFixTest extends TestCase
{
    use CopiesFixtureDirectories;

    private const FIXTURE = __DIR__ . '/Fixtures/PsalterApp';

    private const TEMPLATE = 'resources/views/psalter.blade.php';

    /** @var array{cases: array<string, string>, template: string, shadows: list<string>}|null */
    private static ?array $run = null;

    /** @return iterable<string, array{string}> */
    public static function cases(): iterable
    {
        $files = \glob(self::FIXTURE . '/cases/*.php');
        \assert($files !== false);
        \sort($files);

        foreach ($files as $file) {
            yield \basename($file) => [\basename($file)];
        }
    }

    #[Test]
    #[DataProvider('cases')]
    public function psalter_leaves_the_case_as_its_expected_source(string $case): void
    {
        $this->assertFileExists(self::FIXTURE . '/expected/' . $case, "Case {$case} has no expected/ counterpart.");

        $this->assertSame(
            (string) \file_get_contents(self::FIXTURE . '/expected/' . $case),
            $this->psalterRun()['cases'][$case],
        );
    }

    /** A Blade finding lives in a generated shadow: an edit there would never reach the template. */
    #[Test]
    public function a_blade_finding_is_rewritten_in_neither_the_template_nor_its_shadow(): void
    {
        $run = $this->psalterRun();

        $this->assertSame((string) \file_get_contents(self::FIXTURE . '/' . self::TEMPLATE), $run['template']);
        $this->assertNotSame([], $run['shadows'], 'The fixture compiled no shadow, so the test proves nothing.');

        foreach ($run['shadows'] as $shadow) {
            $this->assertStringContainsString('->singleton(', $shadow);
            $this->assertStringNotContainsString('->scoped(', $shadow);
        }
    }

    /** @return array{cases: array<string, string>, template: string, shadows: list<string>} */
    private function psalterRun(): array
    {
        if (self::$run !== null) {
            return self::$run;
        }

        $psalter = \dirname(__DIR__, 3) . '/vendor/bin/psalter';
        $this->assertFileExists($psalter, 'Psalter binary not found, run composer install.');

        $project = \sys_get_temp_dir() . '/psalm-laravel-psalter-' . \getmypid();
        self::removeDirectory($project);
        self::copyDirectory(self::FIXTURE, $project);

        try {
            $process = new Process(
                [\PHP_BINARY, $psalter, '--config=psalm.xml', '--no-cache', '--threads=1', '--no-progress', '--issues=OctaneIncompatibleBinding'],
                $project,
                ['XDG_CACHE_HOME' => $project . '/.cache', 'TMPDIR' => $project . '/'],
            );
            $process->setTimeout(300);
            $process->run();

            $this->assertSame(0, $process->getExitCode(), "Psalter failed:\n{$process->getOutput()}\n{$process->getErrorOutput()}");

            $cases = [];

            foreach (self::cases() as [$case]) {
                $cases[$case] = (string) \file_get_contents($project . '/cases/' . $case);
            }

            $shadows = [];

            foreach (\glob($project . '/.cache/blade-shadows/*-*.php') ?: [] as $shadow) {
                $shadows[] = (string) \file_get_contents($shadow);
            }

            return self::$run = [
                'cases' => $cases,
                'template' => (string) \file_get_contents($project . '/' . self::TEMPLATE),
                'shadows' => $shadows,
            ];
        } finally {
            self::removeDirectory($project);
        }
    }
}
