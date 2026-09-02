<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Eloquent\BuilderFluentReturnHandler;
use Symfony\Component\Process\Process;

/**
 * Whole-project regression coverage for #1448. A phpt only analyzes the one file it is passed,
 * but findUnusedCode needs Psalm's whole-project dead-code consolidation, so this runs a real
 * fixture project as a subprocess instead.
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
            ['app/Models/PostBuilder.php', 27], // publishedIntersectionBuilderPrimary() @return self&FluentContract
            ['app/Models/PostBuilder.php', 39], // publishedIntersectionBuilderSecondary() @return FluentContract&self
            ['app/Models/PostBuilder.php', 46], // publishedStaticNative(): static
            ['app/Models/PostBuilder.php', 52], // publishedStaticDocblock() @return static
            ['app/Models/PostBuilder.php', 59], // publishedOwnClassName(): PostBuilder
            ['app/Models/PostBuilder.php', 78], // forGuest(): static — static, always checked, unaffected either way
        ];
        foreach ($notReported as [$fileSuffix, $line]) {
            $this->assertNull(
                $this->findingType($findings, $fileSuffix, $line),
                "Expected no unused-return-value finding at {$fileSuffix}:{$line}.",
            );
        }

        $this->assertSame(
            'PossiblyUnusedReturnValue',
            $this->findingType($findings, 'app/Models/PostBuilder.php', 67),
            'Expected the non-fluent discardedControl() control to remain reportable.',
        );

        // The spot checks above only assert on specific lines; without this, a regression that
        // adds a spurious finding anywhere else in the fixture would pass silently.
        $this->assertCount(
            1,
            $findings,
            'Expected exactly one unused-return-value finding (the discardedControl control): ' . \json_encode($findings),
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
