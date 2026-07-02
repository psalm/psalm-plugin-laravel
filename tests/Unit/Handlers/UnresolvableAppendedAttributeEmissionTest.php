<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Rules\UnresolvableAppendedAttributeHandler;
use Symfony\Component\Process\Process;

/**
 * End-to-end guard for the actual emission of {@see UnresolvableAppendedAttributeHandler}. The pure
 * verdict is unit-tested in {@see UnresolvableAppendedAttributeHandlerTest}, but that never runs the
 * orchestration (warm-up to registry to IssueBuffer): the rule reads the ModelMetadataRegistry, which
 * ModelRegistrationHandler warms only for autoloadable model classes, so a psalm-tester `.phpt`
 * fixture (passed as a file argument, never registered) cannot reach it. This points a real Psalm
 * subprocess at a self-contained fixture project whose models ARE autoloadable, so the registry warms
 * them and the handler fires for real.
 *
 * The fixture hosts one unbacked append (must be reported) and three controls that must NOT be:
 * accessor-backed, cast-backed (a first-party Castable the registry shapes as Primitive, the exact
 * false positive the any-cast-backs design fixes), and an unbacked-but-hidden append (dropped before
 * the throwing loop). Asserting exactly one finding proves the rule both fires on the real bug and
 * stays silent on all three — and would fail loudly if a future change silently no-op'd it.
 *
 * Like {@see SuppressScopeUnusedCodeTest}, this forks a real `vendor/bin/psalm` (it boots Laravel via
 * the plugin, ~6s) because the emission cannot be observed in-process. It lives in tests/Unit for
 * proximity to the handler it guards.
 */
#[CoversClass(UnresolvableAppendedAttributeHandler::class)]
final class UnresolvableAppendedAttributeEmissionTest extends TestCase
{
    #[Test]
    public function it_reports_only_the_unbacked_append(): void
    {
        $findings = $this->runPsalmAndCollectAppendFindings();

        $messages = \array_map(
            static fn(array $finding): string => $finding['message'],
            $findings,
        );
        $joined = \implode("\n", $messages);

        // Exactly one finding: the unbacked append fires, and the accessor-, cast-, and hidden-backed
        // controls stay silent. assertCount(1) is the regression guard — a change that broke emission,
        // or that flagged a backed/hidden entry, fails here.
        $this->assertCount(1, $findings, "Expected exactly one UnresolvableAppendedAttribute finding, got:\n{$joined}");
        $this->assertStringContainsString('UnbackedAppendModel', $messages[0]);
        $this->assertStringContainsString("'avatar_url'", $messages[0]);

        // The controls must never appear (assertCount(1) already implies this; spelled out for a clear
        // failure message if one regresses).
        foreach (['AccessorBackedAppendModel', 'CastBackedAppendModel', 'HiddenAppendModel'] as $clean) {
            $this->assertStringNotContainsString($clean, $joined, "{$clean} has a backed/hidden append and must not be reported.");
        }
    }

    /**
     * @return list<array{type: string, message: string}>
     */
    private function runPsalmAndCollectAppendFindings(): array
    {
        $projectRoot = \dirname(__DIR__, 3);
        $fixtureDir = __DIR__ . '/Fixtures/UnresolvableAppendedAttribute';
        $psalmBinary = $projectRoot . '/vendor/bin/psalm';

        $this->assertFileExists($psalmBinary, 'Psalm binary not found — run composer install.');

        $process = new Process(
            [\PHP_BINARY, $psalmBinary, '-c', 'psalm.xml', '--no-cache', '--threads=1', '--no-progress', '--output-format=json'],
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
            if (!\is_array($finding) || !isset($finding['type'], $finding['message'])) {
                continue;
            }

            if ($finding['type'] === 'UnresolvableAppendedAttribute') {
                $findings[] = ['type' => $finding['type'], 'message' => (string) $finding['message']];
            }
        }

        return $findings;
    }
}
