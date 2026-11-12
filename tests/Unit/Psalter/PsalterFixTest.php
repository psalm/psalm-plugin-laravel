<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Psalter;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Rules\ImplicitQueryBuilderCallHandler;
use Psalm\LaravelPlugin\Handlers\Rules\OctaneIncompatibleBindingHandler;
use Psalm\LaravelPlugin\Internal\ClassDescendants;
use Psalm\LaravelPlugin\Internal\ModelCallGuard;
use Psalm\LaravelPlugin\Internal\PsalterFix;
use Psalm\LaravelPlugin\Plugin;
use Symfony\Component\Process\Process;
use Tests\Psalm\LaravelPlugin\Unit\Concerns\CopiesFixtureDirectories;

/**
 * End-to-end guard for the plugin's Psalter fixes: ONE real `vendor/bin/psalter` per fixture app, whose rewritten
 * files are compared byte for byte with their expected copies, next to ONE plain `vendor/bin/psalm` run of the same
 * app that proves a case left unchanged is still REPORTED (a declined fix must not pass because the rule went quiet).
 *
 * Adding a case is adding two files and nothing else: `cases/<Issue>/<Name>.php` is the input and
 * `expected/<Issue>/<Name>.php` what Psalter must leave in it (the input itself for a case that must NOT
 * be rewritten). The data provider discovers them, and the fixture's psalm.xml already declares every
 * fixable issue. Models a case needs live in `app/Models` (autoloaded; case files are only parsed).
 *
 * `PsalterApp` is the main fixture. `PsalterAppContainerOverride` is separate because a scanned container subclass
 * that overrides `scoped()` makes every `Container`-typed receiver of its fixture a declined one, so it cannot share
 * an app with the cases that prove such a receiver is renamed.
 */
#[CoversClass(Plugin::class)]
#[CoversClass(PsalterFix::class)]
#[CoversClass(ModelCallGuard::class)]
#[CoversClass(ClassDescendants::class)]
#[CoversClass(ImplicitQueryBuilderCallHandler::class)]
#[CoversClass(OctaneIncompatibleBindingHandler::class)]
#[Group('subprocess')]
final class PsalterFixTest extends TestCase
{
    use CopiesFixtureDirectories;

    /** Psalter accepts only issues a plugin declares; the fixture config declares all of these. */
    private const FIXABLE_ISSUES = ['ImplicitQueryBuilderCall', 'OctaneIncompatibleBinding'];

    private const FIXTURES_DIR = __DIR__ . '/Fixtures';

    /** The template and the file of the PsalterApp fixture that Blade compiles it to. */
    private const BLADE_TEMPLATE = 'resources/views/psalter.blade.php';

    /**
     * How many times a plain run reports a case's own issue (the directory name) in each case Psalter leaves
     * unchanged: one per declined call site, so a site that stops being reported fails the test, and a site that
     * starts being fixed shows up as a case that is no longer unchanged. Zero is a case where the rule reports
     * nothing (a suppressed finding, a real method, `bind()`, `scoped()`), not a declined fix. A new unchanged
     * case adds its own line.
     */
    private const REPORTED_SITES = [
        'PsalterApp/ImplicitQueryBuilderCall/CloneThisInClosure.php' => 1,
        'PsalterApp/ImplicitQueryBuilderCall/DescendantDeclaresCalledMethod.php' => 1,
        'PsalterApp/ImplicitQueryBuilderCall/DescendantOverridesMagicCall.php' => 2,
        'PsalterApp/ImplicitQueryBuilderCall/FirstClassCallable.php' => 2,
        'PsalterApp/ImplicitQueryBuilderCall/ForwardCallToOverride.php' => 1,
        'PsalterApp/ImplicitQueryBuilderCall/MagicDispatchOverride.php' => 4,
        'PsalterApp/ImplicitQueryBuilderCall/ObjectContextAncestor.php' => 1,
        'PsalterApp/ImplicitQueryBuilderCall/RealModelMethod.php' => 0,
        'PsalterApp/ImplicitQueryBuilderCall/StaticClosureClassScope.php' => 2,
        'PsalterApp/ImplicitQueryBuilderCall/StaticInModel.php' => 3,
        'PsalterApp/ImplicitQueryBuilderCall/Suppressed.php' => 0,
        'PsalterApp/ImplicitQueryBuilderCall/TraitHosted.php' => 1,
        'PsalterApp/OctaneIncompatibleBinding/Bind.php' => 0,
        'PsalterApp/OctaneIncompatibleBinding/SafeSingleton.php' => 0,
        'PsalterApp/OctaneIncompatibleBinding/Suppressed.php' => 0,
        'PsalterApp/OctaneIncompatibleBinding/TraitHosted.php' => 1,
        'PsalterApp/OctaneIncompatibleBinding/UserContainerSubclass.php' => 1,
        'PsalterAppContainerOverride/OctaneIncompatibleBinding/BaseTypedContainer.php' => 2,
    ];

    /** @var array<string, array{rewritten: array<string, string>, reported: array<string, list<string>>, template: string, shadows: list<string>}> */
    private static array $runs = [];

    /** @return iterable<string, array{string, string}> case id => [fixture, case path relative to cases/] */
    public static function cases(): iterable
    {
        foreach (self::fixtures() as $fixture) {
            $files = \glob(self::FIXTURES_DIR . '/' . $fixture . '/cases/*/*.php');
            \assert($files !== false);
            \sort($files);

            foreach ($files as $file) {
                $relative = \substr($file, \strlen(self::FIXTURES_DIR . '/' . $fixture . '/cases/'));

                yield $fixture . '/' . $relative => [$fixture, $relative];
            }
        }
    }

    /** @return iterable<string, array{string, string}> the cases whose expected file is their input */
    public static function unchangedCases(): iterable
    {
        foreach (self::cases() as $id => [$fixture, $case]) {
            $dir = self::FIXTURES_DIR . '/' . $fixture;

            if (\is_file($dir . '/expected/' . $case) && \file_get_contents($dir . '/expected/' . $case) === \file_get_contents($dir . '/cases/' . $case)) {
                yield $id => [$fixture, $case];
            }
        }
    }

    #[Test]
    #[DataProvider('cases')]
    public function psalter_leaves_the_case_as_its_expected_source(string $fixture, string $case): void
    {
        $expectedFile = self::FIXTURES_DIR . '/' . $fixture . '/expected/' . $case;
        $this->assertFileExists($expectedFile, "Case {$case} has no expected/ counterpart.");

        $this->assertSame((string) \file_get_contents($expectedFile), $this->fixtureRun($fixture)['rewritten'][$case]);
    }

    #[Test]
    #[DataProvider('unchangedCases')]
    public function a_case_psalter_leaves_unchanged_is_still_reported_by_a_plain_run(string $fixture, string $case): void
    {
        $id = $fixture . '/' . $case;
        $this->assertArrayHasKey($id, self::REPORTED_SITES, "Add {$id} to REPORTED_SITES with the number of sites a plain run reports.");

        $issue = \dirname($case);
        $reported = \array_count_values($this->fixtureRun($fixture)['reported']['cases/' . $case] ?? [])[$issue] ?? 0;

        $this->assertSame(
            self::REPORTED_SITES[$id],
            $reported,
            "Plain psalm reports {$reported} {$issue} in {$case}: a declined site that is no longer reported passes only because the rule went quiet.",
        );
    }

    /** A Blade call is reported on the template (never on the shadow Psalm analyzed) and left unchanged in both. */
    #[Test]
    public function a_blade_call_is_reported_on_the_template_and_rewritten_nowhere(): void
    {
        $run = $this->fixtureRun('PsalterApp');

        $this->assertContains(
            'ImplicitQueryBuilderCall',
            $run['reported'][self::BLADE_TEMPLATE] ?? [],
            'The plain run must report the Blade call on its template.',
        );
        $this->assertSame(
            (string) \file_get_contents(self::FIXTURES_DIR . '/PsalterApp/' . self::BLADE_TEMPLATE),
            $run['template'],
            'Psalter rewrote the template.',
        );
        $this->assertNotSame([], $run['shadows'], 'The fixture compiled no shadow, so the test proves nothing.');

        foreach ($run['shadows'] as $shadow) {
            $this->assertStringNotContainsString('newQuery()->', $shadow, 'Psalter rewrote a Blade shadow.');
        }
    }

    /** @return list<string> */
    private static function fixtures(): array
    {
        return ['PsalterApp', 'PsalterAppContainerOverride'];
    }

    /** @return array{rewritten: array<string, string>, reported: array<string, list<string>>, template: string, shadows: list<string>} */
    private function fixtureRun(string $fixture): array
    {
        if (isset(self::$runs[$fixture])) {
            return self::$runs[$fixture];
        }

        $psalter = \dirname(__DIR__, 3) . '/vendor/bin/psalter';
        $psalm = \dirname(__DIR__, 3) . '/vendor/bin/psalm';
        $this->assertFileExists($psalter, 'Psalter binary not found, run composer install.');

        $project = \sys_get_temp_dir() . '/psalm-laravel-psalter-' . \getmypid() . '-' . $fixture;
        self::removeDirectory($project);
        self::copyDirectory(self::FIXTURES_DIR . '/' . $fixture, $project);

        $environment = ['XDG_CACHE_HOME' => $project . '/.cache', 'TMPDIR' => $project . '/'];

        try {
            // Plain psalm first, on the untouched copy: Psalter then rewrites the same files.
            $plain = new Process([\PHP_BINARY, $psalm, '--config=psalm.xml', '--no-cache', '--threads=1', '--no-progress', '--output-format=json'], $project, $environment);
            $plain->setTimeout(300);
            // Not mustRun(): the fixture reports issues on purpose (exit code 2).
            $plain->run();

            $reported = $this->reportedIssues($plain->getOutput(), $plain->getErrorOutput());

            $process = new Process(
                [
                    \PHP_BINARY, $psalter, '--config=psalm.xml', '--no-cache',
                    '--threads=1', '--no-progress', '--issues=' . \implode(',', self::FIXABLE_ISSUES),
                ],
                $project,
                $environment,
            );
            $process->setTimeout(300);
            $process->run();

            $output = $process->getOutput() . "\n" . $process->getErrorOutput();
            $this->assertSame(0, $process->getExitCode(), "Psalter failed:\n{$output}");

            $rewritten = [];

            foreach (self::cases() as [$caseFixture, $case]) {
                if ($caseFixture === $fixture) {
                    $rewritten[$case] = (string) \file_get_contents($project . '/cases/' . $case);
                }
            }

            $shadows = [];

            foreach (\glob($project . '/.cache/blade-shadows/*.php') ?: [] as $shadow) {
                $shadows[] = (string) \file_get_contents($shadow);
            }

            $templateFile = $project . '/' . self::BLADE_TEMPLATE;

            return self::$runs[$fixture] = [
                'rewritten' => $rewritten,
                'reported' => $reported,
                'template' => \is_file($templateFile) ? (string) \file_get_contents($templateFile) : '',
                'shadows' => $shadows,
            ];
        } finally {
            self::removeDirectory($project);
        }
    }

    /** @return array<string, list<string>> file (relative to the project) => issue types reported there */
    private function reportedIssues(string $json, string $errorOutput): array
    {
        $decoded = \json_decode($json, true);
        $this->assertIsArray($decoded, "Plain psalm did not emit a JSON report.\n{$json}\n{$errorOutput}");

        $reported = [];

        foreach ($decoded as $issue) {
            $this->assertIsArray($issue);
            $file = $issue['file_name'] ?? null;
            $type = $issue['type'] ?? null;
            $this->assertIsString($file);
            $this->assertIsString($type);
            $reported[$file][] = $type;
        }

        return $reported;
    }
}
