<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Filesystem\StorageHandler;
use Symfony\Component\Process\Process;

/**
 * End-to-end guard for {@see StorageHandler}'s UnconfiguredFilesystemDisk emission. The
 * `UnconfiguredFilesystemDiskTest.phpt` type test can only assert silence: the psalm-tester harness boots
 * the Testbench fallback, which leaves the rule disarmed. This forks a real `vendor/bin/psalm` (~6s)
 * against a fixture with its own `bootstrap/app.php` and `config/filesystems.php`, so the rule arms
 * with the fixture's disks and each reported and declined call shape is observed for real.
 */
#[CoversClass(StorageHandler::class)]
final class UnconfiguredFilesystemDiskEmissionTest extends TestCase
{
    #[Test]
    public function it_reports_only_unconfigured_disk_names(): void
    {
        $projectRoot = \dirname(__DIR__, 3);
        $psalmBinary = $projectRoot . '/vendor/bin/psalm';

        $this->assertFileExists($psalmBinary, 'Psalm binary not found — run composer install.');

        $process = new Process(
            [\PHP_BINARY, $psalmBinary, '--no-cache', '--threads=1', '--no-progress', '--output-format=json'],
            __DIR__ . '/Fixtures/UnconfiguredFilesystemDisk',
        );
        $process->setTimeout(300);
        // Psalm exits non-zero when it reports issues; that is expected here, so do not mustRun().
        $process->run();

        $stdout = $process->getOutput();
        $decoded = \json_decode($stdout, true);

        $this->assertIsArray($decoded, "Psalm did not return a JSON array.\nstdout:\n{$stdout}\nstderr:\n{$process->getErrorOutput()}");

        $messages = [];
        foreach ($decoded as $finding) {
            if (\is_array($finding) && ($finding['type'] ?? null) === 'UnconfiguredFilesystemDisk') {
                $messages[] = (string) $finding['message'];
            }
        }

        // Exact set: each flagged shape fires; the configured disk, falsy, dotted, dynamic and
        // DI-manager calls stay silent.
        $this->assertEqualsCanonicalizing(
            [
                "Disk 's3-old' is not configured in filesystems.disks",
                "Disk 's3-old' is not configured in filesystems.disks",
                "Disk 'missing-alias' is not configured in filesystems.disks",
                "Disk 'publik' is not configured in filesystems.disks, did you mean 'public'?",
                "Disk 'tenant' is not configured in filesystems.disks",
            ],
            $messages,
        );
    }
}
