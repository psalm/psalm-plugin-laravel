<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers;

use Illuminate\Filesystem\Filesystem;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Application\ContainerResolver;
use Psalm\LaravelPlugin\Handlers\Collections\CollectionFlattenHandler;
use Psalm\LaravelPlugin\Handlers\Collections\HigherOrderCollectionProxyHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\CustomCollectionHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadataRegistryBuilder;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelAggregateLoadHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelAggregatePropertyHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelRegistrationHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelRelationshipPropertyHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\CastResolver;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\SchemaAggregator;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\ModelPropertyResolver;
use Psalm\LaravelPlugin\Handlers\References\IndirectMethodReferenceHandler;
use Psalm\LaravelPlugin\Handlers\Rules\UndefinedModelRelationHandler;
use Psalm\LaravelPlugin\Internal\ClassLineage;
use Symfony\Component\Process\Process;

/**
 * Regression guard for `\is_a($class, X::class, true)` on class names taken from analyzed types: it
 * autoloads the class, and a load-time deprecation then crashes the whole run (Psalm's error handler
 * turns it into an exception) or, inside the registry warm-up's catch, drops the model's metadata.
 *
 * Not reproducible as a `.phpt`: phpt-declared classes aren't Composer-autoloadable, so
 * `is_a(..., true)` never fires their file. Forks a real `vendor/bin/psalm` over self-contained
 * fixtures instead, like {@see UnknownModelAttributeEmissionTest}. One Psalm project per site under
 * `cases/`: the first crash ends a run, so a shared project would let one site's crash mask another.
 *
 * Every project also analyzes `probe/plugin_active.php`, which is issue-free only while the plugin is
 * registered: an init-time failure disables the plugin silently, which would otherwise pass as "no
 * crash".
 */
#[CoversClass(UndefinedModelRelationHandler::class)]
#[CoversClass(ClassLineage::class)]
#[CoversClass(ModelAggregateLoadHandler::class)]
#[CoversClass(ModelPropertyResolver::class)]
#[CoversClass(CollectionFlattenHandler::class)]
#[CoversClass(CustomCollectionHandler::class)]
#[CoversClass(ModelRelationshipPropertyHandler::class)]
#[CoversClass(ModelAggregatePropertyHandler::class)]
#[CoversClass(ModelRegistrationHandler::class)]
#[CoversClass(ModelMetadataRegistryBuilder::class)]
#[CoversClass(CastResolver::class)]
#[CoversClass(SchemaAggregator::class)]
#[CoversClass(HigherOrderCollectionProxyHandler::class)]
#[CoversClass(IndirectMethodReferenceHandler::class)]
#[CoversClass(ContainerResolver::class)]
#[Group('subprocess')]
final class UndefinedRelationAutoloadCrashTest extends TestCase
{
    private const FIXTURE_DIR = __DIR__ . '/Fixtures/UndefinedRelationAutoloadCrash';

    #[Test]
    public function it_does_not_crash_when_the_relation_receiver_class_deprecates_on_load(): void
    {
        $this->analyze(self::FIXTURE_DIR);
    }

    /** @return iterable<string, array{string}> */
    public static function autoloadSites(): iterable
    {
        yield 'ModelAggregateLoadHandler: assigned value is a model' => ['AggregateAssignment'];
        yield 'ModelAggregateLoadHandler: chain receiver is a query' => ['AggregateQueryChain'];
        yield 'ModelPropertyResolver: pluck value is a model' => ['PluckValue'];
        yield 'CollectionFlattenHandler: value is Enumerable' => ['CollectionFlatten'];
        yield 'CustomCollectionHandler: union has several models' => ['CustomCollection'];
        yield 'ModelRelationshipPropertyHandler: return type is a relation' => ['RelationshipProperty'];
        yield 'ModelAggregatePropertyHandler: return type is a relation' => ['AggregateProperty'];
        yield 'model method return type: registry warm-up, write types, relation references' => ['ModelMethodReturn'];
        yield 'CastResolver: castUsing() target at warm-up' => ['CastUsing'];
        yield 'HigherOrderCollectionProxyHandler: receiver is Enumerable' => ['HigherOrderProxy'];
        yield 'ContainerResolver: unresolvable abstract names a class' => ['ContainerMake'];
        yield 'CastResolver: caster named directly in $casts' => ['DirectCast'];
        yield 'SchemaAggregator: foreignIdFor() class at plugin init' => ['ForeignIdFor'];
        yield 'ContainerResolver: binding resolves to a class-name string' => ['ContainerStringBinding'];
        // Not an autoload site: lineage must resolve a `class_alias()` name, as is_a() did.
        yield 'ClassLineage: relation return type is a class alias' => ['AliasRelation'];
    }

    #[Test]
    #[DataProvider('autoloadSites')]
    public function it_does_not_autoload_a_class_named_by_an_analyzed_type(string $case): void
    {
        $this->analyze(self::FIXTURE_DIR . '/cases/' . $case);
    }

    /**
     * Runs Psalm over the fixture project and fails on a crash, a dropped model, or a failed type check
     * (a handler that declined, or the plugin-active probe).
     */
    private function analyze(string $fixtureDir): void
    {
        $psalmBinary = \dirname(__DIR__, 3) . '/vendor/bin/psalm';

        $this->assertFileExists($psalmBinary, 'Psalm binary not found — run composer install.');

        // The plugin caches the parsed migration schema keyed by migration contents, outside --no-cache:
        // a cached schema would skip the init-time schema build this test must exercise.
        $cacheDir = \sys_get_temp_dir() . '/psalm-laravel-autoload-crash-' . \bin2hex(\random_bytes(6));
        $process = new Process(
            [\PHP_BINARY, $psalmBinary, '-c', 'psalm.xml', '--no-cache', '--threads=1', '--no-progress', '--output-format=json'],
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
