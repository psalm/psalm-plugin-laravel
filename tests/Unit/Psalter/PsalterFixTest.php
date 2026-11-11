<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Psalter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Rules\ModelMakeHandler;
use Psalm\LaravelPlugin\Internal\PsalterFix;
use Psalm\LaravelPlugin\Plugin;
use Symfony\Component\Process\Process;
use Tests\Psalm\LaravelPlugin\Unit\Concerns\CopiesFixtureDirectories;

/**
 * End-to-end guard for the plugin's Psalter fixes: ONE real `vendor/bin/psalter` over ONE fixture app
 * (Fixtures/PsalterApp), whose rewritten files are compared byte for byte with their expected copies.
 *
 * Adding a case is adding two files and nothing else: `cases/<Issue>/<Name>.php` is the input and
 * `expected/<Issue>/<Name>.php` what Psalter must leave in it (the input itself for a case that must NOT
 * be rewritten). The data provider discovers them, and the fixture's psalm.xml already declares every
 * fixable issue. Models a case needs live in `app/Models` (autoloaded; case files are only parsed).
 *
 * Psalter writes `(new Post())` whatever the target PHP version (it targets 8.3 here), so the output
 * does not depend on the PHP running the suite.
 */
#[CoversClass(Plugin::class)]
#[CoversClass(PsalterFix::class)]
#[CoversClass(ModelMakeHandler::class)]
#[Group('subprocess')]
final class PsalterFixTest extends TestCase
{
    use CopiesFixtureDirectories;

    /** Psalter accepts only issues a plugin declares; the fixture config declares all of these. */
    private const FIXABLE_ISSUES = ['ModelMakeDiscouraged', 'ImplicitQueryBuilderCall', 'OctaneIncompatibleBinding'];

    private const FIXTURE = __DIR__ . '/Fixtures/PsalterApp';

    /** @var array<string, string>|null case path relative to cases/ => contents after Psalter ran */
    private static ?array $rewritten = null;

    /** @return iterable<string, array{string}> */
    public static function cases(): iterable
    {
        $files = \glob(self::FIXTURE . '/cases/*/*.php');
        \assert($files !== false);
        \sort($files);

        foreach ($files as $file) {
            $relative = \substr($file, \strlen(self::FIXTURE . '/cases/'));

            yield $relative => [$relative];
        }
    }

    #[Test]
    #[DataProvider('cases')]
    public function psalter_leaves_the_case_as_its_expected_source(string $case): void
    {
        $expectedFile = self::FIXTURE . '/expected/' . $case;
        $this->assertFileExists($expectedFile, "Case {$case} has no expected/ counterpart.");

        $this->assertSame((string) \file_get_contents($expectedFile), $this->rewrittenCases()[$case]);
    }

    /** @return array<string, string> */
    private function rewrittenCases(): array
    {
        if (self::$rewritten !== null) {
            return self::$rewritten;
        }

        $psalter = \dirname(__DIR__, 3) . '/vendor/bin/psalter';
        $this->assertFileExists($psalter, 'Psalter binary not found, run composer install.');

        $project = \sys_get_temp_dir() . '/psalm-laravel-psalter-' . \getmypid();
        self::removeDirectory($project);
        self::copyDirectory(self::FIXTURE, $project);

        try {
            $process = new Process(
                [
                    \PHP_BINARY, $psalter, '--config=psalm.xml', '--php-version=8.3', '--no-cache',
                    '--threads=1', '--no-progress', '--issues=' . \implode(',', self::FIXABLE_ISSUES),
                ],
                $project,
                ['XDG_CACHE_HOME' => $project . '/.cache', 'TMPDIR' => $project . '/'],
            );
            $process->setTimeout(300);
            $process->run();

            $output = $process->getOutput() . "\n" . $process->getErrorOutput();
            $this->assertSame(0, $process->getExitCode(), "Psalter failed:\n{$output}");

            $rewritten = [];

            foreach (self::cases() as [$case]) {
                $rewritten[$case] = (string) \file_get_contents($project . '/cases/' . $case);
            }

            return self::$rewritten = $rewritten;
        } finally {
            self::removeDirectory($project);
        }
    }
}
