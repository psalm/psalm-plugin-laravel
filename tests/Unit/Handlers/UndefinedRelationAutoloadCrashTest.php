<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadataRegistryBuilder;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelAggregateLoadHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\CastResolver;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;
use Psalm\LaravelPlugin\Handlers\Rules\UndefinedModelRelationHandler;
use Symfony\Component\Process\Process;

/**
 * Regression guard for `\is_a($class, X::class, true)` on class names from analyzed types (#1253, #1652):
 * it autoloads the class, and a load-time deprecation (`app/Deprecated*.php`) crashes the whole run or,
 * where caught, drops a cast or the whole plugin, failing a `@psalm-check-type-exact` in `app/usage.php`.
 *
 * Not reproducible as a `.phpt`: phpt-declared classes aren't Composer-autoloadable, so
 * `is_a(..., true)` never fires their file. Forks a real `vendor/bin/psalm` over a self-contained
 * fixture instead, like {@see UnknownModelAttributeEmissionTest}.
 */
#[CoversClass(UndefinedModelRelationHandler::class)]
#[CoversClass(ModelAggregateLoadHandler::class)]
#[CoversClass(CastResolver::class)]
#[CoversClass(ModelMetadataRegistryBuilder::class)]
#[CoversClass(SchemaAggregator::class)]
#[Group('subprocess')]
final class UndefinedRelationAutoloadCrashTest extends TestCase
{
    #[Test]
    public function it_does_not_autoload_a_class_named_by_an_analyzed_type(): void
    {
        $projectRoot = \dirname(__DIR__, 3);
        $fixtureDir = __DIR__ . '/Fixtures/UndefinedRelationAutoloadCrash';
        $psalmBinary = $projectRoot . '/vendor/bin/psalm';

        $this->assertFileExists($psalmBinary, 'Psalm binary not found — run composer install.');

        // A private cache: the plugin caches the migration schema outside --no-cache, skipping the init-time build.
        $cacheDir = \sys_get_temp_dir() . '/psalm-laravel-autoload-crash-' . \bin2hex(\random_bytes(6));
        $process = new Process(
            [\PHP_BINARY, $psalmBinary, '-c', 'psalm.xml', '--no-cache', '--threads=1', '--scan-threads=1', '--no-progress', '--output-format=json'],
            $fixtureDir,
            ['XDG_CACHE_HOME' => $cacheDir, 'TMPDIR' => $cacheDir . '/'],
        );
        $process->setTimeout(300);

        try {
            // Non-zero exit on findings is fine here; do not mustRun().
            $process->run();
        } finally {
            (new Filesystem())->deleteDirectory($cacheDir);
        }

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
        $this->assertSame([], \array_filter($decoded, static fn(mixed $issue): bool => \is_array($issue) && $issue['type'] === 'CheckType'), "A type check failed.\nstdout:\n{$stdout}");
    }
}
