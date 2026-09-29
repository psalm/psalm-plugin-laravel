<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Filesystem\StorageHandler;
use Psalm\LaravelPlugin\Internal\Ast\ClassConstStringResolver;
use Symfony\Component\Process\Process;

/**
 * End-to-end guard for {@see StorageHandler}'s UnconfiguredFilesystemDisk emission. The
 * `UnconfiguredFilesystemDiskTest.phpt` type test can only assert silence: the psalm-tester harness boots
 * the Testbench fallback, which leaves the rule disarmed. This forks a real `vendor/bin/psalm` (~6s)
 * against a fixture with its own `bootstrap/app.php` and `config/filesystems.php`, so the rule arms
 * with the fixture's disks and every name shape (literal, enum case, class constant) and receiver
 * form (facade, root `\Storage` alias, DI manager) is observed firing, or declining, for real.
 */
#[CoversClass(StorageHandler::class)]
#[CoversClass(ClassConstStringResolver::class)]
final class UnconfiguredFilesystemDiskEmissionTest extends TestCase
{
    #[Test]
    public function it_reports_unconfigured_disk_names_across_name_shapes_and_receivers(): void
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

        $reported = [];
        foreach ($decoded as $finding) {
            if (\is_array($finding) && ($finding['type'] ?? null) === 'UnconfiguredFilesystemDisk') {
                $message = (string) $finding['message'];
                $reported[] = \preg_match("/^Disk '([^']*)'/", $message, $m) === 1 ? $m[1] : $message;
            }
        }

        // Exact multiset: proves each shape fires AND that the known names, `static::`, the DI
        // manager, the int-backed enum, falsy and dotted names, and dynamic/null/empty names stay
        // silent. Order-insensitive because the fixture spans two files.
        $this->assertEqualsCanonicalizing(
            [
                's3-old',          // self::OLD
                'missing-literal', // Storage::disk('...')
                'missing-alias',   // \Storage::disk('...')
                'archive-legacy',  // string-backed enum case
                'backups',         // pure enum: enum_value() yields the case name
                'archive-legacy',  // \Storage::drive() with an enum case
                's3-old',          // DiskNames::OLD
                'archive-copy',    // constant expression resolved by Psalm
                's3-old',          // DiskUsage: Storage::disk('s3-old')
            ],
            $reported,
        );
    }
}
