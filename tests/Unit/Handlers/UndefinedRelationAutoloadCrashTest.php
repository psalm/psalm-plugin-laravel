<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Application\ContainerResolver;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadataRegistryBuilder;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelAggregateLoadHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelRegistrationHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\CastResolver;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;
use Psalm\LaravelPlugin\Handlers\References\IndirectMethodReferenceHandler;
use Psalm\LaravelPlugin\Handlers\Rules\UndefinedModelRelationHandler;
use Psalm\LaravelPlugin\Internal\ClassLineage;
use Symfony\Component\Process\Process;

/**
 * Regression guard for `\is_a($class, X::class, true)` on class names taken from analyzed types: it
 * autoloads the class, and a load-time deprecation then crashes the whole run (Psalm's error handler
 * turns it into an exception) or, inside the registry warm-up's catch, drops the model's metadata.
 *
 * A real `vendor/bin/psalm` subprocess, not a `.phpt`: phpt-declared classes aren't Composer-autoloadable,
 * so `is_a(..., true)` never fires their file.
 *
 * One project covers every site: each site names its own deprecated-on-load class (one per `app/<Site>/`),
 * so a clean run proves none was loaded, and a regressed site crashes the run with a trace naming it.
 *
 * `probe/plugin_active.php` is issue-free only while the plugin is registered: an init-time failure
 * disables the plugin silently, which would otherwise pass as "no crash".
 */
#[CoversClass(UndefinedModelRelationHandler::class)]
#[CoversClass(ClassLineage::class)]
#[CoversClass(ModelAggregateLoadHandler::class)]
#[CoversClass(ModelRegistrationHandler::class)]
#[CoversClass(ModelMetadataRegistryBuilder::class)]
#[CoversClass(IndirectMethodReferenceHandler::class)]
#[CoversClass(CastResolver::class)]
#[CoversClass(SchemaAggregator::class)]
#[CoversClass(ContainerResolver::class)]
#[Group('subprocess')]
final class UndefinedRelationAutoloadCrashTest extends TestCase
{
    #[Test]
    public function it_does_not_autoload_a_class_named_by_an_analyzed_type(): void
    {
        $psalmBinary = \dirname(__DIR__, 3) . '/vendor/bin/psalm';
        $this->assertFileExists($psalmBinary, 'Psalm binary not found — run composer install.');

        // The plugin caches the parsed migration schema keyed by migration contents, outside --no-cache:
        // a cached schema would skip the init-time schema build this test must exercise.
        $cacheDir = \sys_get_temp_dir() . '/psalm-laravel-autoload-crash-' . \bin2hex(\random_bytes(6));
        // --scan-threads too: --threads=1 bounds analysis only, scanning defaults to one worker per CPU.
        $process = new Process(
            [\PHP_BINARY, $psalmBinary, '-c', 'psalm.xml', '--no-cache', '--threads=1', '--scan-threads=1', '--no-progress', '--output-format=json'],
            __DIR__ . '/Fixtures/UndefinedRelationAutoloadCrash',
            ['XDG_CACHE_HOME' => $cacheDir, 'TMPDIR' => $cacheDir . '/'],
            timeout: 120,
        );

        try {
            // Non-zero exit on findings is fine here; do not mustRun().
            $process->run();
        } finally {
            (new Filesystem())->deleteDirectory($cacheDir);
        }

        $stdout = $process->getOutput();
        $stderr = $process->getErrorOutput();
        $combined = $stdout . "\n" . $stderr;
        $context = "\nstdout:\n{$stdout}\nstderr:\n{$stderr}";

        $this->assertStringNotContainsString('crashed due to an uncaught Throwable', $combined, 'Psalm crashed instead of completing analysis.' . $context);
        $this->assertStringNotContainsString('Uncaught', $combined, 'Psalm crashed instead of completing analysis.' . $context);

        $decoded = \json_decode($stdout, true);
        $this->assertIsArray($decoded, 'Psalm did not return a JSON array — analysis likely crashed.' . $context);

        // The registry warm-up catches the exception, so a lost model surfaces only as this warning.
        $this->assertStringNotContainsString('warm-up failed', $stderr, 'Model metadata was dropped.' . $context);

        $failed = \array_values(\array_filter(
            $decoded,
            static fn(mixed $issue): bool => \is_array($issue)
                && (($issue['type'] ?? null) === 'CheckType' || \str_ends_with((string) ($issue['file_name'] ?? ''), 'plugin_active.php')),
        ));
        $this->assertSame([], $failed, 'A type check failed (a handler declined) or the plugin disabled itself.' . $context);
    }
}
