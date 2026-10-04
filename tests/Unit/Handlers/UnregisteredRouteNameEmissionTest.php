<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Rules\UnregisteredRouteNameHandler;
use Symfony\Component\Process\Process;

/**
 * End-to-end guard for {@see UnregisteredRouteNameHandler}'s emission. The psalm-tester type-test
 * harness boots the Testbench fallback, which never loads route files, so the rule can only fire
 * for real in a Psalm subprocess against a fixture with a real `bootstrap/app.php` and
 * `withRouting()`. Covers every receiver the handler registers for, positional and named-argument
 * call shapes, and the shapes that must stay silent (registered name, spread, non-literal, enum).
 *
 * Lives in tests/Unit next to {@see UnknownModelAttributeEmissionTest}, the same convention.
 */
#[CoversClass(UnregisteredRouteNameHandler::class)]
final class UnregisteredRouteNameEmissionTest extends TestCase
{
    #[Test]
    public function it_reports_unregistered_route_names_through_every_covered_receiver_and_stays_silent_otherwise(): void
    {
        $findings = $this->runPsalmAndCollectFindings();
        $joined = \implode("\n", \array_column($findings, 'message'));

        // 8 positional (route(), to_route(), URL::route/signedRoute/temporarySignedRoute, Redirect::route(),
        // redirect()->route(), url()->route() on the Contracts\UrlGenerator url() returns with no path)
        // + 3 named-argument (route(absolute:, name:) with the name at offset 1, to_route(route:),
        // redirect()->route(route:)). An exact count proves both full receiver coverage and that the
        // clean call, spread, non-literal and enum shapes do not over-fire.
        $this->assertCount(11, $findings, "Expected exactly 11 UnregisteredRouteName findings, got:\n{$joined}");
        $this->assertStringNotContainsString("'dashboard'", $joined);
        $this->assertStringNotContainsString("'posts.show'", $joined);
        // The rule is opt-in but reports at Psalm's normal level once enabled.
        $this->assertSame(\array_fill(0, 11, 'error'), \array_column($findings, 'severity'));
    }

    /**
     * @return list<array{type: string, message: string, severity: string}>
     */
    private function runPsalmAndCollectFindings(string $config = 'psalm.xml'): array
    {
        $projectRoot = \dirname(__DIR__, 3);
        $fixtureDir = __DIR__ . '/Fixtures/UnregisteredRouteName';
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

            if ($finding['type'] === 'UnregisteredRouteName') {
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
