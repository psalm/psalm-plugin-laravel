<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\ViewReferenceCollector;
use Psalm\LaravelPlugin\Blade\ViewReferenceRegistry;
use Psalm\LaravelPlugin\Handlers\Views\UnusedViewHandler;
use Symfony\Component\Process\Process;

/**
 * End-to-end proof that a Blade template with no statically-provable reference is reported
 * ({@see \Psalm\LaravelPlugin\Issues\UnusedView}), and that one unresolvable reference anywhere
 * in the project turns the whole check off for the run.
 *
 * A real `vendor/bin/psalm` run is the only way to pin this: reference collection spans both the
 * compile pass (template-side, `@include`/`@extends`) and a full `AfterCodebasePopulated` walk of
 * every project file (call-site, `view()`), so nothing short of a real run exercises both halves
 * together.
 */
#[CoversClass(UnusedViewHandler::class)]
#[CoversClass(ViewReferenceCollector::class)]
#[CoversClass(ViewReferenceRegistry::class)]
final class UnusedViewTest extends TestCase
{
    private const FIXTURE = __DIR__ . '/Fixtures/UnusedView';

    private const DYNAMIC_FIXTURE = __DIR__ . '/Fixtures/UnusedViewDynamic';

    private const ISSUE = 'UnusedView';

    protected function setUp(): void
    {
        $this->deleteShadowDir(self::FIXTURE);
        $this->deleteShadowDir(self::DYNAMIC_FIXTURE);
    }

    protected function tearDown(): void
    {
        $this->deleteShadowDir(self::FIXTURE);
        $this->deleteShadowDir(self::DYNAMIC_FIXTURE);
    }

    private function deleteShadowDir(string $fixture): void
    {
        $shadowDir = $fixture . '/.cache/blade-shadows';

        if (!\is_dir($shadowDir)) {
            return;
        }

        foreach (\array_diff(\scandir($shadowDir) ?: [], ['.', '..']) as $entry) {
            \unlink($shadowDir . '/' . $entry);
        }

        \rmdir($shadowDir);
    }

    /**
     * @return list<array{type: string, file: string, message: string}>
     */
    private function unusedViewIssues(string $fixture, string $config): array
    {
        $psalmBinary = \dirname(__DIR__, 3) . '/vendor/bin/psalm';
        $this->assertFileExists($psalmBinary, 'Psalm binary not found — run composer install.');

        $process = new Process(
            [\PHP_BINARY, $psalmBinary, '-c', $config, '--no-cache', '--threads=1', '--no-progress', '--output-format=json'],
            $fixture,
        );
        $process->setTimeout(300);
        // Not mustRun(): the fixture reports an issue on purpose.
        $process->run();

        $decoded = \json_decode($process->getOutput(), true);
        $this->assertIsArray($decoded, "Psalm did not emit a JSON report.\n{$process->getOutput()}\n{$process->getErrorOutput()}");

        $issues = [];

        foreach ($decoded as $issue) {
            $this->assertIsArray($issue);
            $type = (string) $issue['type'];

            if ($type !== self::ISSUE) {
                continue;
            }

            $issues[] = [
                'type' => $type,
                'file' => \basename((string) $issue['file_name']),
                'message' => (string) $issue['message'],
            ];
        }

        return $issues;
    }

    #[Test]
    public function a_template_no_call_site_ever_renders_is_reported(): void
    {
        $issues = $this->unusedViewIssues(self::FIXTURE, 'psalm.xml');

        $this->assertCount(1, $issues, \var_export($issues, true));
        $this->assertSame('orphan.blade.php', $issues[0]['file']);
        $this->assertStringContainsString('orphan', $issues[0]['message']);
    }

    /**
     * A partial only ever reached through `@include` from another template must not be reported —
     * template-side references are collected at compile time from the compiled shadow.
     */
    #[Test]
    public function a_template_only_reached_through_include_is_not_reported(): void
    {
        $issues = $this->unusedViewIssues(self::FIXTURE, 'psalm.xml');

        $this->assertSame([], \array_values(\array_filter(
            $issues,
            static fn(array $issue): bool => $issue['file'] === 'partial.blade.php',
        )));
    }

    /**
     * A layout only ever reached through `@extends` must not be reported, for the same reason as
     * the `@include`d partial.
     */
    #[Test]
    public function a_template_only_reached_through_extends_is_not_reported(): void
    {
        $issues = $this->unusedViewIssues(self::FIXTURE, 'psalm.xml');

        $this->assertSame([], \array_values(\array_filter(
            $issues,
            static fn(array $issue): bool => $issue['file'] === 'layout.blade.php',
        )));
    }

    /**
     * One `@include($name)` with a non-literal argument anywhere in the project makes the whole
     * reference set unreliable, so the check must decline for every template in the run — including
     * the otherwise-orphan `orphan.blade.php`.
     */
    #[Test]
    public function a_dynamic_reference_anywhere_turns_the_check_off_for_the_whole_run(): void
    {
        $issues = $this->unusedViewIssues(self::DYNAMIC_FIXTURE, 'psalm.xml');

        $this->assertSame([], $issues, \var_export($issues, true));
    }

    #[Test]
    public function the_check_is_off_unless_the_config_flag_opts_in(): void
    {
        $issues = $this->unusedViewIssues(self::FIXTURE, 'psalm-unused-off.xml');

        $this->assertSame([], $issues, \var_export($issues, true));
    }
}
