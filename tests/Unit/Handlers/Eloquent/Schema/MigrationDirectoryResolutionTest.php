<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Schema;

use Illuminate\Foundation\Application;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\MigrationSchemaBuilder;
use Psalm\LaravelPlugin\Providers\ApplicationProvider;
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

        $this->assertSame(['/fake-app/database/migrations'], $directories);
        $this->assertNotEmpty($progress->warnings, 'A warning must surface the missing migrator service.');
        $this->assertStringContainsString('migrator', (string) $progress->warnings[0]);
    }

    #[Test]
    public function merges_registered_paths_when_migrator_is_available(): void
    {
        $app = $this->fakeApp(migratorBound: true, migratorPaths: ['/extra/package/migrations']);
        $progress = $this->capturingProgress();

        $directories = $this->resolveDirectories($app, $progress);

        $this->assertSame(['/extra/package/migrations', '/fake-app/database/migrations'], $directories);
        $this->assertSame([], $progress->warnings, 'No warning when the migrator resolves cleanly.');
    }

    #[Test]
    public function warning_surfaces_boot_mode_and_swallowed_bootstrap_error(): void
    {
        // The plugin swallows a partial-bootstrap throwable to stay alive; that throwable is
        // the real reason the migrator is missing, so the warning must surface it (#1170).
        $this->setBootState('testbench_fallback', new \RuntimeException('parse error in config/app.php'));

        $app = $this->fakeApp(migratorBound: false, migratorPaths: []);
        $progress = $this->capturingProgress();

        $this->resolveDirectories($app, $progress);

        $this->assertStringContainsString('boot mode: testbench_fallback', (string) $progress->warnings[0]);
        $this->assertStringContainsString('parse error in config/app.php', (string) $progress->warnings[0]);
    }

    protected function tearDown(): void
    {
        // Boot state is global static — reset so it can't leak into other tests.
        $this->setBootState(null, null);
        parent::tearDown();
    }

    private function setBootState(?string $bootMode, ?\Throwable $bootstrapError): void
    {
        (new \ReflectionProperty(ApplicationProvider::class, 'bootMode'))->setValue(null, $bootMode);
        (new \ReflectionProperty(ApplicationProvider::class, 'bootstrapError'))->setValue(null, $bootstrapError);
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
