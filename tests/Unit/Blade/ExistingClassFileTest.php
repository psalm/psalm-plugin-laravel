<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use Composer\Autoload\ClassLoader;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\ExistingClassFile;

#[CoversClass(ExistingClassFile::class)]
final class ExistingClassFileTest extends TestCase
{
    private ?ClassLoader $loader = null;

    protected function tearDown(): void
    {
        $this->loader?->unregister();
    }

    private function registerLoader(string $class, string $file): void
    {
        $this->loader = new ClassLoader(\sys_get_temp_dir() . '/existing-class-file-' . \getmypid());
        $this->loader->addClassMap([$class => $file]);
        $this->loader->register(false);
    }

    #[Test]
    public function the_configured_composer_lookup_answers_first_and_is_normalized(): void
    {
        $file = (string) \realpath(__FILE__);

        $this->assertSame($file, ExistingClassFile::find('Some\Unloaded', static fn(): string => \dirname($file) . '/../Blade/' . \basename($file)));
    }

    #[Test]
    public function an_unknown_name_has_no_file(): void
    {
        $this->assertNull(ExistingClassFile::find('Nobody\Declares\This', static fn(): false => false));
    }

    /** PHP class names are case-insensitive, and Psalm keys its storage by the lowercased name. */
    #[Test]
    public function a_differently_cased_name_still_finds_the_classmap_entry(): void
    {
        $this->registerLoader('Mapped\ProjectReport', __FILE__);

        $this->assertSame(__FILE__, ExistingClassFile::find('mapped\projectreport', static fn(): false => false));
    }

    #[Test]
    public function a_class_the_process_already_declared_answers_with_its_file(): void
    {
        $this->assertSame((string) \realpath(__FILE__), ExistingClassFile::find(self::class, static fn(): false => false));
    }
}
