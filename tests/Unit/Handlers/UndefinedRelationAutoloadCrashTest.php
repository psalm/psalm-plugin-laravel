<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Rules\UndefinedModelRelationHandler;
use Symfony\Component\Process\Process;

/**
 * Regression guard for {@see UndefinedModelRelationHandler}'s pre-fix `\is_a($fqcn, Model::class,
 * true)`, which autoloaded the receiver class and let a load-time deprecation crash the whole run.
 *
 * Not reproducible as a `.phpt`: phpt-declared classes aren't Composer-autoloadable, so
 * `is_a(..., true)` never fires their file. Forks a real `vendor/bin/psalm` over a self-contained
 * fixture instead, like {@see UnknownModelAttributeEmissionTest}.
 */
#[CoversClass(UndefinedModelRelationHandler::class)]
final class UndefinedRelationAutoloadCrashTest extends TestCase
{
    #[Test]
    public function it_does_not_crash_when_the_relation_receiver_class_deprecates_on_load(): void
    {
        $projectRoot = \dirname(__DIR__, 3);
        $fixtureDir = __DIR__ . '/Fixtures/UndefinedRelationAutoloadCrash';
        $psalmBinary = $projectRoot . '/vendor/bin/psalm';

        $this->assertFileExists($psalmBinary, 'Psalm binary not found — run composer install.');

        $process = new Process(
            [\PHP_BINARY, $psalmBinary, '-c', 'psalm.xml', '--no-cache', '--threads=1', '--no-progress', '--output-format=json'],
            $fixtureDir,
        );
        $process->setTimeout(300);
        // Non-zero exit on findings is fine here; do not mustRun().
        $process->run();

        $stdout = $process->getOutput();
        $stderr = $process->getErrorOutput();
        $combined = $stdout . "\n" . $stderr;

        $this->assertStringNotContainsString(
            'crashed due to an uncaught Throwable',
            $combined,
            "Psalm crashed instead of completing analysis.\nstdout:\n{$stdout}\nstderr:\n{$stderr}",
        );
        $this->assertStringNotContainsString(
            'Uncaught',
            $combined,
            "Psalm crashed instead of completing analysis.\nstdout:\n{$stdout}\nstderr:\n{$stderr}",
        );

        $decoded = \json_decode($stdout, true);
        $this->assertIsArray(
            $decoded,
            "Psalm did not return a JSON array — analysis likely crashed.\nstdout:\n{$stdout}\nstderr:\n{$stderr}",
        );
    }
}
