<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Filesystem\StorageHandler;
use Symfony\Component\Process\Process;

/**
 * End-to-end guard for the actual emission of {@see StorageHandler}'s UnknownFilesystemDisk
 * diagnostic. `UnknownFilesystemDiskTest.phpt` only guards the silent defer under the psalm-tester
 * harness (Testbench package-mode boot, `ApplicationProvider::getBootMode() !== 'bootstrap'`), so the
 * rule never gets to fire there — see that phpt's own docblock. This points a real Psalm subprocess
 * at a self-contained fixture project with a real `bootstrap/app.php` and `config/filesystems.php`,
 * so `initUnknownFilesystemDiskHandler()`'s boot-mode gate arms {@see StorageHandler}'s check for real
 * and the rule fires against an actual configured disk list, same idiom as
 * {@see UnknownModelAttributeEmissionTest}.
 */
#[CoversClass(StorageHandler::class)]
final class UnknownFilesystemDiskEmissionTest extends TestCase
{
    #[Test]
    public function it_reports_unconfigured_disks_and_stays_silent_on_configured_ones(): void
    {
        $findings = $this->runPsalmAndCollectFindings();

        $messages = \array_map(
            static fn(array $finding): string => $finding['message'],
            $findings,
        );
        $joined = \implode("\n", $messages);

        $this->assertCount(1, $findings, "Expected exactly 1 UnknownFilesystemDisk finding, got:\n{$joined}");
        $this->assertStringContainsString("'s3-old'", $joined, 'An unconfigured disk literal must be flagged.');
        $this->assertStringNotContainsString("'local'", $joined, 'A disk present in filesystems.disks must not be flagged.');
    }

    /**
     * @return list<array{type: string, message: string}>
     */
    private function runPsalmAndCollectFindings(): array
    {
        $projectRoot = \dirname(__DIR__, 3);
        $fixtureDir = __DIR__ . '/Fixtures/UnknownFilesystemDisk';
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

            if ($finding['type'] === 'UnknownFilesystemDisk') {
                $findings[] = [
                    'type' => $finding['type'],
                    'message' => (string) $finding['message'],
                ];
            }
        }

        return $findings;
    }
}
