<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Rules\UnknownModelAttributeHandler;
use Symfony\Component\Process\Process;

/**
 * End-to-end guard for the actual emission of {@see UnknownModelAttributeHandler}. The pure verdict
 * is unit-tested in {@see UnknownModelAttributeHandlerTest}, and the `UnknownModelAttributeTest.phpt`
 * type test only guards the schema-empty defer — the psalm-tester harness boots Testbench with no
 * migrations, so no app model there ever has a populated schema and the rule never gets to fire. This
 * points a real Psalm subprocess at a self-contained fixture project with a real migration, so the
 * registry resolves an actual column schema and the rule fires for real — including through the
 * `static::create()` / `self::create()` receiver forms used idiomatically inside a model's own
 * methods, which a name-resolution bug once let bypass the rule silently (no FQCN rewrite for
 * `self`/`static`, unlike a plain class reference).
 *
 * Like {@see UnresolvableAppendedModelAttributeEmissionTest}, this forks a real `vendor/bin/psalm` (it
 * boots Laravel via the plugin, ~6s) because the emission cannot be observed in-process. It lives in
 * tests/Unit for proximity to the handler it guards.
 */
#[CoversClass(UnknownModelAttributeHandler::class)]
final class UnknownModelAttributeEmissionTest extends TestCase
{
    #[Test]
    public function it_reports_typos_through_every_receiver_form_and_stays_silent_on_clean_calls(): void
    {
        $findings = $this->runPsalmAndCollectFindings();

        $messages = \array_map(
            static fn(array $finding): string => $finding['message'],
            $findings,
        );
        $joined = \implode("\n", $messages);

        // One finding per typo'd key, through each of the three receiver forms exercised by the
        // fixture: static::, self::, and a plain external class reference. The three "clean" call
        // sites (makeClean, create_clean_from_outside) and the dynamically declared migration
        // column must stay silent — asserting an exact count proves both that the rule fires and
        // that it does not over-fire when a registered table has no statically parsed columns.
        $this->assertCount(3, $findings, "Expected exactly 3 UnknownModelAttribute findings, got:\n{$joined}");
        $this->assertStringContainsString("'nmae'", $joined, 'static::create() with a typo must be flagged.');
        $this->assertStringContainsString("'unknown_col'", $joined, 'self::create() with a typo must be flagged.');
        $this->assertStringContainsString("'bad_key'", $joined, 'A plain external Model::create() with a typo must be flagged.');
        $this->assertStringNotContainsString("'real_col'", $joined, 'A dynamically declared migration column must not be flagged.');
        $this->assertSame(['info', 'info', 'info'], \array_column($findings, 'severity'));
    }

    #[Test]
    public function experimental_enforcement_promotes_the_same_findings_to_errors(): void
    {
        $findings = $this->runPsalmAndCollectFindings('psalm-experimental.xml');

        $this->assertCount(3, $findings);
        $this->assertSame(['error', 'error', 'error'], \array_column($findings, 'severity'));
    }

    /**
     * @return list<array{type: string, message: string, severity: string}>
     */
    private function runPsalmAndCollectFindings(string $config = 'psalm.xml'): array
    {
        $projectRoot = \dirname(__DIR__, 3);
        $fixtureDir = __DIR__ . '/Fixtures/UnknownModelAttribute';
        $psalmBinary = $projectRoot . '/vendor/bin/psalm';

        $this->assertFileExists($psalmBinary, 'Psalm binary not found — run composer install.');

        $process = new Process(
            [\PHP_BINARY, $psalmBinary, '-c', $config, '--no-cache', '--threads=1', '--no-progress', '--show-info=true', '--output-format=json'],
            $fixtureDir,
        );
        $process->setTimeout(300);
        // Psalm exits non-zero when it reports issues; that is expected here, so do not mustRun().
        $process->run();

        $stdout = $process->getOutput();
        $decoded = \json_decode($stdout, true);

        $this->assertIsArray($decoded, "Psalm did not return a JSON array.\nstdout:\n{$stdout}\nstderr:\n{$process->getErrorOutput()}");

        $findings = [];
        foreach ($decoded as $finding) {
            if (!\is_array($finding) || !isset($finding['type'], $finding['message'], $finding['severity'])) {
                continue;
            }

            if ($finding['type'] === 'UnknownModelAttribute') {
                $findings[] = [
                    'type' => $finding['type'],
                    'message' => (string) $finding['message'],
                    'severity' => (string) $finding['severity'],
                ];
            }
        }

        return $findings;
    }
}
