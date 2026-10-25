<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\ProjectFileInjector;

/**
 * The real target is `ProjectAnalyzer`'s private `$project_files`, which a unit test cannot build.
 * These doubles stand in for the shapes Psalm could present after an internal change: the property
 * gone, renamed, or no longer an array.
 */
#[CoversClass(ProjectFileInjector::class)]
final class ProjectFileInjectorTest extends TestCase
{
    #[Test]
    public function adds_paths_keyed_by_themselves(): void
    {
        $target = new class {
            /** @var array<string, string> */
            private array $project_files = ['/app/Foo.php' => '/app/Foo.php'];

            /** @return array<string, string> */
            public function read(): array
            {
                return $this->project_files;
            }
        };

        $this->assertTrue(ProjectFileInjector::inject($target, ['/views/a.blade.php', '/views/b.blade.php']));
        $this->assertSame(
            [
                '/app/Foo.php' => '/app/Foo.php',
                '/views/a.blade.php' => '/views/a.blade.php',
                '/views/b.blade.php' => '/views/b.blade.php',
            ],
            $target->read(),
        );
    }

    #[Test]
    public function keeps_an_existing_entry_untouched(): void
    {
        $target = new class {
            /** @var array<string, string> */
            private array $project_files = ['/views/a.blade.php' => '/views/a.blade.php'];

            /** @return array<string, string> */
            public function read(): array
            {
                return $this->project_files;
            }
        };

        $this->assertTrue(ProjectFileInjector::inject($target, ['/views/a.blade.php']));
        $this->assertSame(['/views/a.blade.php' => '/views/a.blade.php'], $target->read());
    }

    #[Test]
    public function declines_when_the_property_does_not_exist(): void
    {
        $this->assertFalse(ProjectFileInjector::inject(new class {}, ['/views/a.blade.php']));
    }

    #[Test]
    public function declines_when_the_property_is_not_an_array(): void
    {
        $target = new class {
            private ?string $project_files = null;

            public function read(): ?string
            {
                return $this->project_files;
            }
        };

        $this->assertFalse(ProjectFileInjector::inject($target, ['/views/a.blade.php']));
        $this->assertNull($target->read());
    }
}
