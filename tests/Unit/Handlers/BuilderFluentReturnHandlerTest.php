<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Eloquent\BuilderFluentReturnHandler;
use Symfony\Component\Process\Process;

/**
 * Whole-project regression coverage for #1448. Psalm's dead-code consolidation does not inspect
 * an explicitly passed file, so this runs a real fixture project with findUnusedCode enabled
 * (see repo memory reference_findunusedcode_test_limits — a phpt cannot exercise this).
 */
#[CoversClass(BuilderFluentReturnHandler::class)]
final class BuilderFluentReturnHandlerTest extends TestCase
{
    #[Test]
    public function it_treats_self_static_and_own_class_returns_as_fluent(): void
    {
        $findings = $this->runPsalmAndCollectFindings(
            __DIR__ . '/Fixtures/BuilderFluentReturn',
            ['PossiblyUnusedReturnValue', 'UnusedReturnValue'],
        );

        $notReported = [
            ['app/Models/PostBuilder.php', 15], // publishedSelf(): self
            ['app/Models/PostBuilder.php', 20], // publishedStaticNative(): static
            ['app/Models/PostBuilder.php', 28], // publishedStaticDocblock() @return static
            ['app/Models/PostBuilder.php', 33], // publishedOwnClassName(): PostBuilder
            ['app/Models/PostBuilder.php', 51], // forGuest(): static — already exempt, is_static
        ];
        foreach ($notReported as [$fileSuffix, $line]) {
            $this->assertNull(
                $this->findingType($findings, $fileSuffix, $line),
                "Expected no unused-return-value finding at {$fileSuffix}:{$line}.",
            );
        }

        $this->assertSame(
            'PossiblyUnusedReturnValue',
            $this->findingType($findings, 'app/Models/PostBuilder.php', 41),
            'Expected the non-fluent discardedControl() control to remain reportable.',
        );
    }

    /**
     * @param list<array{type: string, file_name: string, line_from: int}> $findings
     */
    private function findingType(array $findings, string $fileSuffix, int $line): ?string
    {
        foreach ($findings as $finding) {
            if (\str_ends_with($finding['file_name'], $fileSuffix) && $finding['line_from'] === $line) {
                return $finding['type'];
            }
        }

        return null;
    }

    /**
     * @param list<string> $types
     * @return list<array{type: string, file_name: string, line_from: int}>
     */
    private function runPsalmAndCollectFindings(string $fixtureDir, array $types): array
    {
        $projectRoot = \dirname(__DIR__, 3);
        $psalmBinary = $projectRoot . '/vendor/bin/psalm';

        $this->assertFileExists($psalmBinary, 'Psalm binary not found — run composer install.');

        $process = new Process(
            [
                \PHP_BINARY,
                $psalmBinary,
                '--threads=1',
                '--no-progress',
                '--no-cache',
                '--output-format=json',
            ],
            $fixtureDir,
        );
        $process->setTimeout(300);
        $process->run();

        $stdout = $process->getOutput();
        $this->assertFalse(
            $process->isSuccessful(),
            "The fixture must not silently pass without exercising the discardedControl control.\nstdout:\n{$stdout}\nstderr:\n{$process->getErrorOutput()}",
        );
        $this->assertSame('', \trim($process->getErrorOutput()), 'Psalm emitted an unexpected stderr diagnostic.');
        $decoded = \json_decode($stdout, true);
        $this->assertIsArray($decoded, "Psalm did not return a JSON array.\nstdout:\n{$stdout}");

        $matchedFindings = [];
        foreach ($decoded as $finding) {
            if (!\is_array($finding)
                || !isset($finding['type'], $finding['file_name'], $finding['line_from'])
            ) {
                continue;
            }

            if (\in_array($finding['type'], $types, true)) {
                $matchedFindings[] = [
                    'type' => $finding['type'],
                    'file_name' => (string) $finding['file_name'],
                    'line_from' => (int) $finding['line_from'],
                ];
            }
        }

        return $matchedFindings;
    }
}
