<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema;

use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\MigrationSchemaBuilder;
use Psalm\Progress\Progress;

/**
 * Guards the {@see MigrationSchemaBuilder::getMigrationDirectories()} fallback.
 *
 * A booted app whose container can't resolve the `migrator` service (trimmed or
 * partially-registered providers) must not abort the whole migration-schema
 * feature with an uncaught BindingResolutionException — see issue #1170.
 */
#[CoversClass(MigrationSchemaBuilder::class)]
final class MigrationDirectoryResolutionTest extends TestCase
{
    #[Test]
    public function falls_back_to_default_directory_when_migrator_is_unbound(): void
    {
        // migrator unbound reproduces #1170: make('migrator') would throw, the guard must not.
        $app = $this->fakeApp(migratorBound: false, migratorPaths: []);
        $progress = $this->capturingProgress();

        $directories = $this->resolveDirectories($app, $progress);

        self::assertSame(['/fake-app/database/migrations'], $directories);
        self::assertNotEmpty($progress->warnings, 'A warning must surface the missing migrator service.');
        self::assertStringContainsString('migrator', $progress->warnings[0]);
    }

    #[Test]
    public function merges_registered_paths_when_migrator_is_available(): void
    {
        $app = $this->fakeApp(migratorBound: true, migratorPaths: ['/extra/package/migrations']);
        $progress = $this->capturingProgress();

        $directories = $this->resolveDirectories($app, $progress);

        self::assertSame(
            ['/extra/package/migrations', '/fake-app/database/migrations'],
            $directories,
        );
        self::assertSame([], $progress->warnings, 'No warning when the migrator resolves cleanly.');
    }

    /**
     * Invokes the private resolver without booting the heavy Codebase/MigrationCache
     * collaborators the constructor wants — only the app is exercised here.
     *
     * @return list<string>
     */
    private function resolveDirectories(Application $app, Progress $progress): array
    {
        $builder = (new \ReflectionClass(MigrationSchemaBuilder::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(MigrationSchemaBuilder::class, 'app'))->setValue($builder, $app);

        $method = new \ReflectionMethod(MigrationSchemaBuilder::class, 'getMigrationDirectories');

        /** @var list<string> */
        return $method->invoke($builder, $progress);
    }

    /**
     * A real {@see Application} built *without* its constructor, so the framework
     * boot (provider registration, global container/facade binding) never runs —
     * a full `new Application()` inside a unit test crashes the process. The
     * untyped legacy `$basePath` / `$databasePath` properties tolerate the missing
     * constructor, so `databasePath('migrations')` resolves to `<base>/database/migrations`.
     *
     * @param list<string> $migratorPaths the migrator's registered extra paths (loadMigrationsFrom)
     */
    private function fakeApp(bool $migratorBound, array $migratorPaths): Application
    {
        $app = (new \ReflectionClass(Application::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(Application::class, 'basePath'))->setValue($app, '/fake-app');

        if ($migratorBound) {
            $app->instance('migrator', new class ($migratorPaths) {
                /** @param list<string> $paths */
                public function __construct(private readonly array $paths) {}

                /** @return list<string> */
                public function paths(): array
                {
                    return $this->paths;
                }
            });
        }

        return $app;
    }

    private function capturingProgress(): Progress
    {
        // Progress is abstract but ships concrete no-op methods; only warning() needs capturing.
        return new class extends Progress {
            /** @var list<string> */
            public array $warnings = [];

            #[\Override]
            public function warning(string $message): void
            {
                $this->warnings[] = $message;
            }
        };
    }
}
