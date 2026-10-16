<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\DynamicWhereResolver;
use Symfony\Component\Process\Process;

/**
 * End-to-end guard for dynamic `where{Column}` resolution from migration-schema columns.
 *
 * The psalm-tester harness boots Testbench with no migrations, so every model in `tests/Type` has an
 * empty schema and a phpt cannot prove the positive path. This points a real Psalm subprocess at a
 * fixture project whose model has a migration and NO `@property` docblock.
 */
#[CoversClass(DynamicWhereResolver::class)]
#[Group('subprocess')]
final class DynamicWhereSchemaColumnsEmissionTest extends TestCase
{
    private const RESOLUTION_ISSUES = [
        'UndefinedMagicMethod',
        'MixedMethodCall',
        'MixedReturnStatement',
        'MixedAssignment',
        'InvalidScalarArgument',
        'InvalidArgument',
        'TooManyArguments',
    ];

    #[Test]
    public function it_resolves_schema_only_columns_through_every_call_form(): void
    {
        $findings = $this->runPsalm();

        // Static, instance, relation-chain, multi-segment and custom-builder-wins forms.
        $this->assertSame([], $findings['app/Positive.php'] ?? [], 'Positive.php must report no resolution issues.');
    }

    #[Test]
    public function it_still_reports_unknown_unreachable_and_mistyped_columns(): void
    {
        $messages = $this->runPsalm()['app/Negative.php'] ?? [];
        $joined = \implode("\n", $messages);

        $this->assertCount(3, \array_filter($messages, static fn(string $m): bool => \str_starts_with($m, 'UndefinedMagicMethod') || \str_starts_with($m, 'InvalidScalarArgument')), $joined);
        $this->assertStringContainsString('InvalidScalarArgument: Argument 1 of DynamicWhereFixture\Models\UserFilter::whereuserid', $joined);
        $this->assertStringContainsString('UndefinedMagicMethod: Magic method DynamicWhereFixture\Models\UserFilter::wherenosuchcolumn', $joined);
        $this->assertStringContainsString('UndefinedMagicMethod: Magic method DynamicWhereFixture\Models\UserFilter::whereline1', $joined);
    }

    /**
     * @return array<string, list<string>> "Type: message" per project-relative file, resolution issues only
     */
    private function runPsalm(): array
    {
        $fixtureDir = __DIR__ . '/Fixtures/DynamicWhereSchemaColumns';
        $psalmBinary = \dirname(__DIR__, 3) . '/vendor/bin/psalm';

        $this->assertFileExists($psalmBinary, 'Psalm binary not found — run composer install.');

        $process = new Process(
            [\PHP_BINARY, $psalmBinary, '-c', 'psalm.xml', '--no-cache', '--threads=1', '--no-progress', '--show-info=true', '--output-format=json'],
            $fixtureDir,
        );
        $process->setTimeout(300);
        // Psalm exits non-zero when it reports issues; that is expected here, so do not mustRun().
        $process->run();

        $decoded = \json_decode($process->getOutput(), true);

        $this->assertIsArray($decoded, "Psalm did not return a JSON array.\nstdout:\n{$process->getOutput()}\nstderr:\n{$process->getErrorOutput()}");

        $findings = [];
        foreach ($decoded as $finding) {
            if (!\is_array($finding) || !isset($finding['type'], $finding['message'], $finding['file_name'])) {
                continue;
            }

            if (\in_array($finding['type'], self::RESOLUTION_ISSUES, true)) {
                $findings[(string) $finding['file_name']][] = $finding['type'] . ': ' . $finding['message'];
            }
        }

        return $findings;
    }
}
