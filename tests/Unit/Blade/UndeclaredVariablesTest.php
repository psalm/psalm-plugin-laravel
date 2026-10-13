<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\ContractParser;
use Psalm\LaravelPlugin\Blade\ShadowManifest;

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

    /** @return list<string> */
    private function arguments(string $config): array
    {
        return ['-c', $config, '--no-cache', '--threads=1', '--no-progress', '--output-format=json'];
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

    /** @param list<string> $configs each step runs against the shadow cache its predecessor left */
    private function warmRun(array $configs): array
    {
        $sets = \array_map(fn(string $config): array => $this->arguments($config), $configs);
        \assert($sets !== []);

        return $this->undefinedVariables($this->analyzeFixtureSequence(self::FIXTURE, $sets));
    }

    /** @return list<string> */
    private function coldRun(string $config): array
    {
        return $this->undefinedVariables($this->analyzeFixture(self::FIXTURE, $this->arguments($config)));
    }

    #[Test]
    public function a_typo_and_an_arrow_function_read_are_reported_on_their_template_lines(): void
    {
        // Every other fixture template is silent: the whole list is the two reads in typo.blade.php,
        // so each decline family and the `use ($captured)` clause are pinned by this one assertion.
        $this->assertSame([
            'typo.blade.php:3:UndefinedGlobalVariable:$titel',
            'typo.blade.php:4:UndefinedVariable:$insideArrow',
        ], $this->coldRun('psalm.xml'));
    }

    #[Test]
    public function with_the_flag_off_nothing_is_reported_and_the_net_still_declares_the_name(): void
    {
        $run = $this->analyzeFixture(self::FIXTURE, $this->arguments('psalm-off.xml'));

        $this->assertSame([], $this->undefinedVariables($run));
        $this->assertStringContainsString('@var mixed $titel', $this->shadowsOf($run['shadows']));
    }

    #[Test]
    public function with_the_flag_on_the_net_leaves_the_name_out(): void
    {
        $shadows = $this->shadowsOf($this->analyzeFixture(self::FIXTURE, $this->arguments('psalm.xml'))['shadows']);

        $this->assertStringNotContainsString('@var mixed $titel', $shadows);
        // A `use` clause's name stays declared: an undefined one makes Psalm stop analysing the file.
        $this->assertStringContainsString('@var mixed $captured', $shadows);
    }

    #[Test]
    public function a_warm_second_run_reports_the_same(): void
    {
        $this->assertSame($this->coldRun('psalm.xml'), $this->warmRun(['psalm.xml', 'psalm.xml']));
    }

    #[Test]
    public function toggling_the_flag_invalidates_the_cached_shadows(): void
    {
        $this->assertSame($this->coldRun('psalm.xml'), $this->warmRun(['psalm-off.xml', 'psalm.xml']));
        $this->assertSame([], $this->warmRun(['psalm.xml', 'psalm-off.xml']));
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
