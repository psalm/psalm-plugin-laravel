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
 * An unresolvable `migrator` service (partial bootstrap) must degrade to the default
 * directory, not crash the whole migration-schema feature (#1170). Application and Progress
 * are stubbed, not instantiated — real instances boot framework/Psalm code that crashes the
 * process inside the full unit suite.
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
     * Call the private resolver directly, skipping the Codebase/MigrationCache collaborators.
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
     * Stubbed Application — only the methods the resolver calls; nothing boots.
     *
     * @param list<string> $migratorPaths loadMigrationsFrom() paths the migrator would expose
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

    /** Stubbed Progress that records emitted warnings into {@see self::$warnings}. */
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
