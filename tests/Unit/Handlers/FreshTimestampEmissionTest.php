<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Eloquent\FreshTimestampHandler;
use Symfony\Component\Process\Process;

/**
 * End-to-end guard for `Model::freshTimestamp()`: Laravel returns `Date::now()`, so the type follows the app's
 * configured date class. A real Psalm subprocess runs the `WithPivotSchemaColumns` fixture (its bootstrap reads
 * `WITH_PIVOT_DATE` to configure `Date::use()` the way a service provider would).
 */
#[CoversClass(FreshTimestampHandler::class)]
#[Group('subprocess')]
final class FreshTimestampEmissionTest extends TestCase
{
    private const FRESH_PREFIX = '$_fresh_';

    /** @var array<string, array<string, string>> `@psalm-trace` messages of the fresh-timestamp probes, per env variant */
    private static array $traces = [];

    #[Test]
    public function it_types_a_fresh_timestamp_as_the_default_date_class(): void
    {
        $this->assertFreshTimestampsAre('Illuminate\Support\Carbon', []);
    }

    #[Test]
    public function it_types_a_fresh_timestamp_as_the_configured_date_class(): void
    {
        $this->assertFreshTimestampsAre('Carbon\CarbonImmutable', ['WITH_PIVOT_DATE' => 'immutable']);
    }

    #[Test]
    public function it_types_a_fresh_timestamp_as_a_carbon_when_the_date_class_is_a_callable(): void
    {
        $this->assertFreshTimestampsAre('Carbon\CarbonInterface', ['WITH_PIVOT_DATE' => 'callable']);
    }

    #[Test]
    public function it_keeps_the_declared_type_of_an_override_and_of_other_receivers(): void
    {
        foreach ([[], ['WITH_PIVOT_DATE' => 'immutable'], ['WITH_PIVOT_DATE' => 'callable']] as $env) {
            $traces = $this->runPsalm($env);

            // The override's own native return type, wherever it is declared (model, parent, trait).
            $this->assertSame('DateTimeImmutable', $traces['override'] ?? null);
            $this->assertSame('DateTimeImmutable', $traces['inherited_override'] ?? null);
            $this->assertSame('DateTimeImmutable', $traces['trait_override'] ?? null);
            // A non-model class with a method of the same name is not the handler's business.
            $this->assertSame('int', $traces['plain'] ?? null);
        }
    }

    /**
     * @param array<string, string> $env
     */
    private function assertFreshTimestampsAre(string $expected, array $env): void
    {
        $traces = $this->runPsalm($env);

        // A concrete model, `$this` in a model, and the abstract `Model` receiver all resolve the trait method alike.
        $this->assertSame($expected, $traces['team'] ?? null);
        $this->assertSame($expected, $traces['this'] ?? null);
        $this->assertSame($expected, $traces['base'] ?? null);
    }

    /**
     * @param array<string, string> $env
     * @return array<string, string> trace message (the part after the variable) per probe name, e.g. `team`
     */
    private function runPsalm(array $env): array
    {
        $key = \json_encode($env, \JSON_THROW_ON_ERROR);
        if (isset(self::$traces[$key])) {
            return self::$traces[$key];
        }

        $fixtureDir = __DIR__ . '/Fixtures/WithPivotSchemaColumns';
        $psalmBinary = \dirname(__DIR__, 3) . '/vendor/bin/psalm';

        $this->assertFileExists($psalmBinary, 'Psalm binary not found — run composer install.');

        $process = new Process(
            [\PHP_BINARY, $psalmBinary, '-c', 'psalm.xml', '--no-cache', '--threads=1', '--no-progress', '--show-info=true', '--output-format=json'],
            $fixtureDir,
            $env,
        );
        $process->setTimeout(300);
        // Psalm exits non-zero when it reports issues; that is expected here, so do not mustRun().
        $process->run();

        $decoded = \json_decode($process->getOutput(), true);

        $this->assertIsArray($decoded, "Psalm did not return a JSON array.\nstdout:\n{$process->getOutput()}\nstderr:\n{$process->getErrorOutput()}");

        $traces = [];
        foreach ($decoded as $finding) {
            if (!\is_array($finding) || ($finding['type'] ?? null) !== 'Trace' || !\is_string($finding['message'] ?? null)) {
                continue;
            }

            $message = $finding['message'];
            if (!\str_starts_with($message, self::FRESH_PREFIX)) {
                continue;
            }

            [$variable, $type] = \explode(': ', $message, 2);
            $traces[\substr($variable, \strlen(self::FRESH_PREFIX))] = $type;
        }

        return self::$traces[$key] = $traces;
    }
}
