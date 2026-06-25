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
        // A bare Application registers base providers only — `migrator` stays unbound,
        // so make('migrator') throws BindingResolutionException, reproducing #1170.
        $app = new Application('/tmp/app-without-migrator');
        $progress = $this->capturingProgress();

        $directories = $this->resolveDirectories($app, $progress);

        $this->assertSame(['/tmp/app-without-migrator/database/migrations'], $directories);
        $this->assertNotEmpty($progress->warnings, 'A warning must surface the missing migrator service.');
        $this->assertStringContainsString('migrator', (string) $progress->warnings[0]);
    }

    #[Test]
    public function merges_registered_paths_when_migrator_is_available(): void
    {
        $app = new Application('/tmp/app-with-migrator');
        $app->instance('migrator', new class {
            /** @return list<string> */
            public function paths(): array
            {
                return ['/extra/package/migrations'];
            }
        });
        $progress = $this->capturingProgress();

        $directories = $this->resolveDirectories($app, $progress);

        $this->assertSame(['/extra/package/migrations', '/tmp/app-with-migrator/database/migrations'], $directories);
        $this->assertSame([], $progress->warnings, 'No warning when the migrator resolves cleanly.');
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
