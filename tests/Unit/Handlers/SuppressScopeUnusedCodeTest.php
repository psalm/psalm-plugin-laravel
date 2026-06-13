<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\SuppressHandler;
use Symfony\Component\Process\Process;

/**
 * End-to-end guard for SuppressHandler's Eloquent scope/accessor suppression under findUnusedCode.
 *
 * Why a real Psalm subprocess instead of a phpt: psalm-tester always passes the snippet as an
 * explicit file argument, and Psalm only runs whole-program dead-code detection
 * (UnusedMethod / PossiblyUnusedMethod / UnusedClass) over a config's <projectFiles>, never over
 * file arguments. So a phpt cannot observe these findings at all — "assert empty" would pass with
 * or without the fix and guard nothing. This test points Psalm at a self-contained fixture
 * project (tests/Unit/Handlers/Fixtures/SuppressScopeUnusedCode) with findUnusedCode enabled, the
 * way real applications run it, and asserts the suppression actually takes effect.
 *
 * The fixture hosts all four scope shapes (trait/direct x modern #[Scope]/legacy scopeXxx) and a
 * trait + direct legacy accessor, plus one non-scope control method. Without the trait-aware fix
 * the trait-hosted scope and accessor leak as PossiblyUnusedMethod; with it, only the control
 * remains.
 *
 * Placement note: this is the suite's only test that forks a real `vendor/bin/psalm` subprocess
 * (it boots Laravel via the plugin, ~6s), because whole-program dead-code detection cannot be
 * observed in-process here. It lives in tests/Unit for proximity to the handler it guards rather
 * than in tests/Application, which is reserved for the fresh-app shell harness (laravel-test.sh).
 */
#[CoversClass(SuppressHandler::class)]
final class SuppressScopeUnusedCodeTest extends TestCase
{
    /**
     * Methods Eloquent dispatches indirectly, so each MUST be suppressed (absent from the report).
     * Covers both the trait-hosted forms (the regression this fixes) and the direct forms (kept as
     * no-regression controls). `secret` is deliberately NOT here: a private #[Scope] is structurally
     * unflaggable on a Model (see runtime note below), so asserting its absence would prove nothing.
     */
    private const SUPPRESSED_SCOPE_MARKERS = [
        '::active',               // trait #[Scope]
        '::scopeFlagged',         // trait legacy
        '::getComputedAttribute', // trait legacy accessor
        '::published',            // direct #[Scope]
        '::scopeArchived',        // direct legacy
        '::getDisplayNameAttribute', // direct legacy accessor
    ];

    #[Test]
    public function it_suppresses_trait_and_direct_eloquent_scopes_but_not_a_plain_unused_method(): void
    {
        $unusedMethodFindings = $this->runPsalmAndCollectUnusedMethodFindings();

        $messages = \array_map(
            static fn(array $finding): string => $finding['message'],
            $unusedMethodFindings,
        );
        $joined = \implode("\n", $messages);

        // Surgical control: the one non-scope method that is genuinely never called must stay
        // reported, proving the suppressor narrows to real scopes rather than silencing every
        // unused method on the model. assertCount(1) also guarantees nothing else leaks — including
        // the private `secret` scope, which Psalm never emits for a __call class anyway.
        $this->assertCount(1, $unusedMethodFindings, "Expected exactly one unused-method finding (the non-scope control), got:\n{$joined}");
        $this->assertStringContainsString('TraitScopeModel::helperNonScope', $messages[0]);

        // Every dispatched scope/accessor must be suppressed rather than reported as unused.
        foreach (self::SUPPRESSED_SCOPE_MARKERS as $marker) {
            $this->assertStringNotContainsString($marker, $joined, "Scope/accessor method {$marker} must be suppressed, not reported as unused.");
        }
    }

    /**
     * @return list<array{type: string, message: string}>
     */
    private function runPsalmAndCollectUnusedMethodFindings(): array
    {
        $projectRoot = \dirname(__DIR__, 3);
        $fixtureDir = __DIR__ . '/Fixtures/SuppressScopeUnusedCode';
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

        $unusedMethodFindings = [];
        foreach ($decoded as $finding) {
            if (!\is_array($finding) || !isset($finding['type'], $finding['message'])) {
                continue;
            }

            if ($finding['type'] === 'PossiblyUnusedMethod' || $finding['type'] === 'UnusedMethod') {
                $unusedMethodFindings[] = ['type' => (string) $finding['type'], 'message' => (string) $finding['message']];
            }
        }

        return $unusedMethodFindings;
    }
}
