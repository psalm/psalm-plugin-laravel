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
 *
 * Both Application and Progress are mocked rather than instantiated: a real
 * Application boots framework code, and a hand-rolled Progress subclass risks
 * leaving an abstract Progress method unimplemented — either crashes the PHP
 * process when this runs inside the full unit suite.
 */
#[CoversClass(MigrationSchemaBuilder::class)]
final class MigrationDirectoryResolutionTest extends TestCase
{
    /** @var list<string> */
    private array $warnings = [];

    #[Test]
    public function falls_back_to_default_directory_when_migrator_is_unbound(): void
    {
        // migrator unbound reproduces #1170: make('migrator') would throw, the guard must not.
        $app = $this->fakeApp(migratorBound: false, migratorPaths: []);

        $directories = $this->resolveDirectories($app);

        $this->assertSame(['/fake-app/database/migrations'], $directories);
        $this->assertNotEmpty($this->warnings, 'A warning must surface the missing migrator service.');
        $this->assertStringContainsString('migrator', $this->warnings[0]);
    }

    #[Test]
    public function merges_registered_paths_when_migrator_is_available(): void
    {
        $app = $this->fakeApp(migratorBound: true, migratorPaths: ['/extra/package/migrations']);

        $directories = $this->resolveDirectories($app);

        $this->assertSame(['/extra/package/migrations', '/fake-app/database/migrations'], $directories);
        $this->assertSame([], $this->warnings, 'No warning when the migrator resolves cleanly.');
    }

    #[Test]
    public function warning_surfaces_boot_mode_and_swallowed_bootstrap_error(): void
    {
        // The plugin swallows a partial-bootstrap throwable to stay alive; that throwable is
        // the real reason the migrator is missing, so the warning must surface it (#1170).
        $this->setBootState('testbench_fallback', new \RuntimeException('parse error in config/app.php'));

        $app = $this->fakeApp(migratorBound: false, migratorPaths: []);

        $this->resolveDirectories($app);

        $this->assertStringContainsString('boot mode: testbench_fallback', $this->warnings[0]);
        $this->assertStringContainsString('parse error in config/app.php', $this->warnings[0]);
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
     * Invoke the private resolver without booting the heavy Codebase/MigrationCache
     * collaborators the constructor wants — only the app is exercised here.
     *
     * @return list<string>
     */
    private function resolveDirectories(Application $app): array
    {
        $builder = (new \ReflectionClass(MigrationSchemaBuilder::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(MigrationSchemaBuilder::class, 'app'))->setValue($builder, $app);

        $method = new \ReflectionMethod(MigrationSchemaBuilder::class, 'getMigrationDirectories');

        /** @var list<string> */
        return $method->invoke($builder, $this->recordingProgress());
    }

    /**
     * A mocked {@see Application} — only the three container methods the resolver
     * touches are stubbed; every other method is a mock no-op, so nothing boots.
     *
     * @param list<string> $migratorPaths the migrator's registered extra paths (loadMigrationsFrom)
     */
    private function fakeApp(bool $migratorBound, array $migratorPaths): Application
    {
        $migrator = new class ($migratorPaths) {
            /** @param list<string> $paths */
            public function __construct(private readonly array $paths) {}

            /** @return list<string> */
            public function paths(): array
            {
                return $this->paths;
            }
        };

        $app = $this->createStub(Application::class);
        $app->method('databasePath')->willReturn('/fake-app/database/migrations');
        $app->method('bound')->willReturnCallback(
            static fn(string $abstract): bool => $abstract === 'migrator' && $migratorBound,
        );
        $app->method('make')->willReturnCallback(
            static fn(string $abstract): ?object => $abstract === 'migrator' ? $migrator : null,
        );

        return $app;
    }

    /**
     * A mocked Progress that records the warnings the resolver emits. Mocking sidesteps
     * the abstract-method surface of Progress, which differs across Psalm versions.
     */
    private function recordingProgress(): Progress
    {
        $progress = $this->createStub(Progress::class);
        $progress->method('warning')->willReturnCallback(
            function (string $message): void {
                $this->warnings[] = $message;
            },
        );

        return $progress;
    }
}
