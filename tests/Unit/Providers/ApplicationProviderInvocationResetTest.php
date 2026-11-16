<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Providers;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Codebase;
use Psalm\Internal\Codebase\ClassLikes;
use Psalm\Internal\Provider\ClassLikeStorageProvider;
use Psalm\LaravelPlugin\Bootstrap\ApplicationProvider;
use Psalm\LaravelPlugin\Handlers\Eloquent\Schema\CastResolver;

#[CoversClass(ApplicationProvider::class)]
final class ApplicationProviderInvocationResetTest extends TestCase
{
    #[Test]
    public function reset_forces_the_next_invocation_to_boot_a_fresh_application(): void
    {
        ApplicationProvider::reset();
        ApplicationProvider::bootApp();
        $first = ApplicationProvider::getApp();
        $oldAlias = 'PsalmLaravelPluginOldInvocationAlias' . \str_replace('.', '', \uniqid('', true));
        $loader = \Illuminate\Foundation\AliasLoader::getInstance();
        $loader->alias($oldAlias, \stdClass::class);
        $loader->register();

        ApplicationProvider::reset();

        $this->assertNull(\Illuminate\Support\Facades\Facade::getFacadeApplication());
        $this->assertNotSame($first, \Illuminate\Container\Container::getInstance());
        $this->assertArrayNotHasKey($oldAlias, \Illuminate\Foundation\AliasLoader::getInstance()->getAliases());
        $this->assertFalse(\class_exists($oldAlias));

        ApplicationProvider::bootApp();

        $this->assertNotSame($first, ApplicationProvider::getApp());
    }

    #[Test]
    public function a_later_boot_does_not_take_classes_loaded_after_an_earlier_boot_for_boot_facts(): void
    {
        $suffix = \str_replace('.', '', \uniqid('', true));
        $eager = 'PsalmLaravelPluginEagerAlias' . $suffix;
        $late = 'PsalmLaravelPluginLateAlias' . $suffix;

        ApplicationProvider::reset();
        // Declared before this boot, as a provider or Composer file does; after the reset, which would count it as loaded later.
        \class_alias(\Illuminate\Support\Collection::class, $eager);
        ApplicationProvider::bootApp();

        $this->assertTrue(ApplicationProvider::wasClassDeclaredAtBoot($eager));
        $this->assertFalse(ApplicationProvider::wasClassDeclaredAtBoot($late));

        // What the plugin itself loads after the boot (the `Schema` facade alias): PHP keeps it in the class table.
        \class_alias(\Illuminate\Support\Collection::class, $late);
        $this->assertFalse(ApplicationProvider::wasClassDeclaredAtBoot($late));

        ApplicationProvider::reset();
        ApplicationProvider::bootApp();

        $this->assertTrue(ApplicationProvider::wasClassDeclaredAtBoot($eager));
        $this->assertFalse(ApplicationProvider::wasClassDeclaredAtBoot(\strtoupper($late)));
    }

    #[Test]
    public function a_later_boot_does_not_take_classes_loaded_after_an_aborted_boot_for_boot_facts(): void
    {
        $late = 'PsalmLaravelPluginAbortedLateAlias' . \str_replace('.', '', \uniqid('', true));
        $project = \sys_get_temp_dir() . '/psalm-laravel-aborted-boot-' . \uniqid('', true);
        \mkdir($project . '/bootstrap', 0777, true);
        \file_put_contents($project . '/bootstrap/app.php', "<?php\nthrow new \\RuntimeException('boot aborted');\n");
        $cwd = \getcwd();
        $this->assertIsString($cwd);

        ApplicationProvider::reset();
        \chdir($project);

        try {
            ApplicationProvider::bootApp();
            $this->fail('The boot should have thrown.');
        } catch (\RuntimeException $runtimeException) {
            $this->assertSame('boot aborted', $runtimeException->getMessage());
        } finally {
            \chdir($cwd);
            \unlink($project . '/bootstrap/app.php');
            \rmdir($project . '/bootstrap');
            \rmdir($project);
        }

        // Loaded after the aborted boot, which left no snapshot behind.
        \class_alias(\Illuminate\Support\Collection::class, $late);

        ApplicationProvider::reset();
        ApplicationProvider::bootApp();

        $this->assertFalse(ApplicationProvider::wasClassDeclaredAtBoot($late));
    }

    #[Test]
    public function the_alias_snapshot_ignores_aliases_registered_after_the_boot(): void
    {
        ApplicationProvider::reset();
        ApplicationProvider::bootApp();

        $registered = \array_key_first(\Illuminate\Foundation\AliasLoader::getInstance()->getAliases());
        $this->assertIsString($registered);
        $this->assertTrue(ApplicationProvider::wasAliasRegisteredAtBoot($registered));
        $this->assertFalse(ApplicationProvider::wasAliasRegisteredAtBoot(\strtolower($registered) . 'X'));

        \Illuminate\Foundation\AliasLoader::getInstance()->alias('PsalmLaravelPluginLateRegistered', \stdClass::class);

        $this->assertFalse(ApplicationProvider::wasAliasRegisteredAtBoot('PsalmLaravelPluginLateRegistered'));
    }

    #[Test]
    public function reset_forgets_the_previous_applications_date_class(): void
    {
        $codebase = (new \ReflectionClass(Codebase::class))->newInstanceWithoutConstructor();
        $codebase->classlike_storage_provider = new ClassLikeStorageProvider();
        $codebase->classlikes = (new \ReflectionClass(ClassLikes::class))->newInstanceWithoutConstructor();

        try {
            // `Date::use()` state is a process static: the next invocation's casts must not inherit it.
            \Illuminate\Support\DateFactory::use(\Carbon\CarbonImmutable::class);

            ApplicationProvider::reset();

            $this->assertSame(
                \Illuminate\Support\Carbon::class,
                CastResolver::resolve($codebase, 'datetime', nullable: false)->getId(),
            );
        } finally {
            \Illuminate\Support\DateFactory::useDefault();
        }
    }
}
