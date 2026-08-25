<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Rules\MissingRouteHandler;
use Symfony\Component\Process\Process;

/**
 * End-to-end guard for {@see MissingRouteHandler}'s actual emission. The `MissingRouteTest.phpt`
 * type test only guards the empty-table defer — the psalm-tester harness boots through the
 * Testbench package fallback, which never loads an application's route files, so the named-route
 * table there is always empty and the handler never gets to fire positively. This points a real
 * Psalm subprocess at a self-contained fixture with a real `bootstrap/app.php` and `withRouting()`
 * so the router resolves an actual named-route table and the rule fires for real — across every
 * receiver the handler covers (route(), to_route(), URL::route()/signedRoute()/
 * temporarySignedRoute(), Redirect::route(), redirect()->route()) — while staying silent on a
 * clean call, a leading spread, a non-literal name, and a BackedEnum name.
 *
 * Lives in tests/Unit for proximity to the handler it guards, same convention as
 * {@see UnknownModelAttributeEmissionTest}.
 */
#[CoversClass(MissingRouteHandler::class)]
final class MissingRouteEmissionTest extends TestCase
{
    #[Test]
    public function it_reports_undefined_route_names_through_every_covered_receiver_and_stays_silent_on_clean_calls(): void
    {
        $findings = $this->runPsalmAndCollectFindings();

        $messages = \array_map(
            static fn(array $finding): string => $finding['message'],
            $findings,
        );
        $joined = \implode("\n", $messages);

        // One finding per typo'd receiver call site: route(), to_route(), URL::route(),
        // URL::signedRoute(), URL::temporarySignedRoute(), Redirect::route(), redirect()->route(),
        // url()->route() (the last one hits the \Illuminate\Contracts\Routing\UrlGenerator
        // contract url() returns with no path, a distinct receiver from the concrete class).
        // The clean calls, the spread, the non-literal name, and the enum name must stay silent —
        // asserting an exact count proves both that the rule fires on every covered receiver and
        // that it does not over-fire on the forms it deliberately skips.
        $this->assertCount(8, $findings, "Expected exactly 8 MissingRoute findings, got:\n{$joined}");
        $this->assertStringContainsString("'dashbaord'", $joined, 'Every URL/Redirect-family typo must be flagged.');
        $this->assertStringContainsString("'posts.hsow'", $joined, 'to_route() with a typo must be flagged.');
        $this->assertStringNotContainsString(
            "'dashboard'",
            $joined,
            'A registered route name must never be flagged, on any receiver.',
        );
        $this->assertStringNotContainsString(
            "'posts.show'",
            $joined,
            'A registered route name must never be flagged, on any receiver.',
        );
        $this->assertSame(\array_fill(0, 8, 'info'), \array_column($findings, 'severity'));
    }

    #[Test]
    public function experimental_enforcement_promotes_the_same_findings_to_errors(): void
    {
        $findings = $this->runPsalmAndCollectFindings('psalm-experimental.xml');

        $this->assertCount(8, $findings);
        $this->assertSame(\array_fill(0, 8, 'error'), \array_column($findings, 'severity'));
    }

    /**
     * PluginConfig::fromXml() enables findMissingRoutes under `<experimental>` unless the
     * project sets it explicitly (matching findSerializedQueuedModels's own convention), and
     * ExperimentalIssuePolicy separately promotes MissingRoute's severity to error whenever
     * `<experimental>` is set. This fixture config carries neither `findMissingRoutes` nor an
     * explicit `issueHandlers` entry, so both mechanisms fire from `<experimental value="true" />`
     * alone: the rule turns on AND its findings report as errors, proving the two independent
     * gates (enablement and severity) combine coherently rather than one silently overriding
     * or masking the other.
     */
    #[Test]
    public function experimental_alone_both_enables_the_rule_and_promotes_its_severity(): void
    {
        $findings = $this->runPsalmAndCollectFindings('psalm-experimental-auto-enable.xml');

        $this->assertCount(8, $findings);
        $this->assertSame(\array_fill(0, 8, 'error'), \array_column($findings, 'severity'));
    }

    /**
     * @return list<array{type: string, message: string, severity: string}>
     */
    private function runPsalmAndCollectFindings(string $config = 'psalm.xml'): array
    {
        $projectRoot = \dirname(__DIR__, 3);
        $fixtureDir = __DIR__ . '/Fixtures/MissingRoute';
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

            if ($finding['type'] === 'MissingRoute') {
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
