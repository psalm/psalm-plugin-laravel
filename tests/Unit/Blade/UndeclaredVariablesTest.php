<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\ContractParser;
use Psalm\LaravelPlugin\Blade\ShadowManifest;
use Symfony\Component\Process\Process;

/**
 * End-to-end proof of `<blade reportUndeclaredVariables="true" />` (#1827). The decision depends on
 * the booted app (shared data, composers) and on Psalm's own undefined-variable analysis of the
 * shadow, so only a real `vendor/bin/psalm` run exercises both halves.
 */
#[CoversClass(ContractParser::class)]
#[CoversClass(ShadowManifest::class)]
#[Group('subprocess')]
final class UndeclaredVariablesTest extends TestCase
{
    use AnalysesFixtureApp;

    private const FIXTURE = __DIR__ . '/Fixtures/UndeclaredVariables';

    private const EXPECTED = [
        'typo.blade.php:3:UndefinedGlobalVariable:$titel',
        'typo.blade.php:4:UndefinedVariable:$insideArrow',
    ];

    /** @var list<array{output: string, errorOutput: string, shadows: string}>|null */
    private static ?array $journey = null;

    protected static function resetFixtureAnalyses(): void
    {
        self::$journey = null;
    }

    /**
     * One run per step of a flag-toggling journey, sharing the shadow cache the way repeated runs of a
     * real project do: cold with the flag on, off over the on cache, on again over the off cache, then
     * warm. Every case below reads one step, so the class pays four Psalm boots rather than one per case.
     *
     * @return list<array{output: string, errorOutput: string, shadows: string}>
     */
    private function journey(): array
    {
        if (self::$journey !== null) {
            return self::$journey;
        }

        $shadowDir = $this->startFixtureRun(self::FIXTURE);
        $steps = [];

        foreach (['psalm.xml', 'psalm-off.xml', 'psalm.xml', 'psalm.xml'] as $index => $config) {
            $process = new Process(
                [\PHP_BINARY, $this->psalmBinary(), '-c', $config, '--no-cache', '--threads=1', '--no-progress', '--output-format=json'],
                self::FIXTURE,
            );
            $process->setTimeout(300);
            // Not mustRun(): the fixture reports issues on purpose.
            $process->run();

            $steps[] = [
                'output' => $process->getOutput(),
                'errorOutput' => $process->getErrorOutput(),
                'shadows' => $this->snapshotShadows($shadowDir, "undeclared-journey-{$index}"),
            ];
        }

        return self::$journey = $steps;
    }

    /**
     * @param array{output: string, errorOutput: string, shadows: string} $run
     *
     * @return list<string> `template:line:type`, plus the variable name, for every undefined-variable
     *                      issue reported in a template
     */
    private function undefinedVariables(array $run): array
    {
        $reported = [];

        foreach ($this->decodeIssues($run) as $issue) {
            $file = \basename((string) $issue['file_name']);

            if (\in_array($issue['type'], ['UndefinedGlobalVariable', 'UndefinedVariable'], true)
                && \str_ends_with($file, '.blade.php')
            ) {
                \preg_match('/\$\w+/', (string) $issue['message'], $name);
                $reported[] = "{$file}:{$issue['line_from']}:{$issue['type']}:" . ($name[0] ?? '');
            }
        }

        return $reported;
    }

    #[Test]
    public function a_typo_and_an_arrow_function_read_are_reported_on_their_template_lines(): void
    {
        // Every other fixture template is silent: the whole list is the two reads in typo.blade.php,
        // so each decline family and the `use ($captured)` clause are pinned by this one assertion.
        $this->assertSame(self::EXPECTED, $this->undefinedVariables($this->journey()[0]));
    }

    /**
     * Each of these views reads a name nothing declares, so only its gate keeps the net: the first by
     * the string-keyed namespace map, the second by an anonymous component path, the third by a
     * `Blade::component()` alias naming a view. A boot that choked on the string-keyed maps would
     * degrade the whole run, which leaves no shadow at all and fails this too.
     */
    #[Test]
    public function a_view_a_component_registration_can_render_keeps_its_net(): void
    {
        $shadows = $this->shadowsOf($this->journey()[0]['shadows']);

        foreach (['undeclaredNamespaced', 'undeclaredPathed', 'undeclaredAliased'] as $name) {
            $this->assertStringContainsString("@var mixed \${$name}", $shadows);
        }
    }

    #[Test]
    public function with_the_flag_on_the_net_leaves_the_name_out(): void
    {
        $shadows = $this->shadowsOf($this->journey()[0]['shadows']);

        $this->assertStringNotContainsString('@var mixed $titel', $shadows);
        // A `use` clause's name stays declared: an undefined one makes Psalm stop analysing the file.
        $this->assertStringContainsString('@var mixed $captured', $shadows);
    }

    /** Over the cache the flag-on run left: nothing reported, and the net declares the name again. */
    #[Test]
    public function turning_the_flag_off_invalidates_the_cached_shadows(): void
    {
        $this->assertSame([], $this->undefinedVariables($this->journey()[1]));
        $this->assertStringContainsString('@var mixed $titel', $this->shadowsOf($this->journey()[1]['shadows']));
    }

    #[Test]
    public function turning_the_flag_on_invalidates_the_cached_shadows(): void
    {
        $this->assertSame(self::EXPECTED, $this->undefinedVariables($this->journey()[2]));
    }

    #[Test]
    public function a_warm_second_run_reports_the_same(): void
    {
        $this->assertSame(self::EXPECTED, $this->undefinedVariables($this->journey()[3]));
    }

    private function shadowsOf(string $directory): string
    {
        $contents = '';

        foreach (\glob($directory . '/*.php') ?: [] as $shadow) {
            if (\basename($shadow) !== 'manifest.php') {
                $contents .= (string) \file_get_contents($shadow);
            }
        }

        return $contents;
    }
}
