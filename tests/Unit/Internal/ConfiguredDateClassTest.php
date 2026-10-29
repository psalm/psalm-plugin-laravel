<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Internal;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Carbon\Factory;
use Carbon\FactoryImmutable;
use Illuminate\Container\Container;
use Illuminate\Support\DateFactory;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Codebase;
use Psalm\Internal\Codebase\ClassLikes;
use Psalm\Internal\Provider\ClassLikeStorageProvider;
use Psalm\LaravelPlugin\Internal\ConfiguredDateClass;

/**
 * `Date::use()` state is read from the real DateFactory statics (process-global, reset in tearDown); Psalm's
 * storage is a partial Codebase with only the classes each case registers.
 */
#[CoversClass(ConfiguredDateClass::class)]
final class ConfiguredDateClassTest extends TestCase
{
    private Codebase $codebase;

    private ?\Illuminate\Contracts\Foundation\Application $previousFacadeApp = null;

    /** @var list<string> */
    private const STORAGE_CLASSES = ['DateCarbon', 'DatePlain', CarbonInterface::class];

    #[\Override]
    protected function setUp(): void
    {
        $this->codebase = (new \ReflectionClass(Codebase::class))->newInstanceWithoutConstructor();
        $this->codebase->classlike_storage_provider = new ClassLikeStorageProvider();
        $this->codebase->classlikes = (new \ReflectionClass(ClassLikes::class))->newInstanceWithoutConstructor();

        $this->previousFacadeApp = Facade::getFacadeApplication();
    }

    #[\Override]
    protected function tearDown(): void
    {
        // `Date::use()` is a static of the framework, and ClassLikeStorageProvider keeps its storage in a static too.
        DateFactory::useDefault();
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($this->previousFacadeApp);
        foreach (self::STORAGE_CLASSES as $class) {
            $this->codebase->classlike_storage_provider->remove($class);
        }
    }

    #[Test]
    public function the_unconfigured_app_gets_the_framework_carbon_without_a_storage_lookup(): void
    {
        $this->assertSame(\Illuminate\Support\Carbon::class, $this->resolve());
    }

    #[Test]
    public function a_configured_carbon_in_storage_is_named_by_its_storage(): void
    {
        $this->registerCarbonClasses();

        // The storage name, not the spelling `Date::use()` was given.
        DateFactory::useClass('datecarbon');
        $this->assertSame('DateCarbon', $this->resolve());
    }

    #[Test]
    public function a_configured_class_in_storage_that_is_no_carbon_is_unknown(): void
    {
        $this->registerCarbonClasses();

        DateFactory::useClass('DatePlain');
        $this->assertNull(ConfiguredDateClass::type($this->codebase));
    }

    #[Test]
    public function an_unscanned_runtime_carbon_is_the_interface(): void
    {
        $this->registerCarbonClasses();

        DateFactory::useClass(CarbonImmutable::class);
        $this->assertSame(CarbonInterface::class, $this->resolve());
    }

    #[Test]
    public function an_unscanned_class_that_is_no_carbon_is_unknown(): void
    {
        $this->registerCarbonClasses();

        DateFactory::useClass('DateUnscanned');
        $this->assertNull(ConfiguredDateClass::type($this->codebase));

        DateFactory::useClass(\DateTimeImmutable::class);
        $this->assertNull(ConfiguredDateClass::type($this->codebase));
    }

    #[Test]
    public function a_stock_factory_is_read_through_its_class_name(): void
    {
        $this->registerCarbonClasses();

        DateFactory::useFactory(new Factory([], 'DateCarbon'));
        $this->assertSame('DateCarbon', $this->resolve());

        DateFactory::useFactory(new FactoryImmutable([], 'DatePlain'));
        $this->assertNull(ConfiguredDateClass::type($this->codebase));

        // The same rules as a configured class: an unscanned Carbon is the interface.
        DateFactory::useFactory(new FactoryImmutable([], CarbonImmutable::class));
        $this->assertSame(CarbonInterface::class, $this->resolve());
    }

    #[Test]
    public function a_callable_is_trusted_to_build_carbons(): void
    {
        $this->registerCarbonClasses();

        DateFactory::useCallable(static fn(mixed $date): mixed => $date);
        $this->assertSame(CarbonInterface::class, $this->resolve());
    }

    #[Test]
    public function a_factory_subclass_is_trusted_to_build_carbons(): void
    {
        $this->registerCarbonClasses();

        // A subclass may override `__call()`, so its class name proves nothing: the interface, not 'DatePlain'.
        DateFactory::useFactory(new class ([], 'DatePlain') extends Factory {});
        $this->assertSame(CarbonInterface::class, $this->resolve());
    }

    #[Test]
    public function a_leading_backslash_names_the_same_class(): void
    {
        $this->registerCarbonClasses();

        DateFactory::useClass('\\DateCarbon');
        $this->assertSame('DateCarbon', $this->resolve());

        DateFactory::useClass('\\' . CarbonImmutable::class);
        $this->assertSame(CarbonInterface::class, $this->resolve());

        DateFactory::useClass('\\DatePlain');
        $this->assertNull(ConfiguredDateClass::type($this->codebase));
    }

    #[Test]
    public function a_swapped_facade_root_replaces_the_statics(): void
    {
        $this->registerCarbonClasses();
        $this->bindDateService(new DateFactory());

        // The stock root keeps the `Date::use()` statics.
        DateFactory::useClass('DateCarbon');
        $this->assertSame('DateCarbon', $this->resolve());

        // `Date::swap()` takes over every `Date::` call, so the statics no longer say anything.
        Date::swap(new FactoryImmutable([], 'DateCarbon'));
        DateFactory::useDefault();
        $this->assertSame('DateCarbon', $this->resolve());

        Date::swap(new FactoryImmutable([], 'DatePlain'));
        $this->assertNull(ConfiguredDateClass::type($this->codebase));

        // Not a stock Carbon factory: a `DateFactory` subclass or any other object is trusted to build Carbons.
        Date::swap(new class extends DateFactory {});
        $this->assertSame(CarbonInterface::class, $this->resolve());

        Date::swap(new \stdClass());
        $this->assertSame(CarbonInterface::class, $this->resolve());
    }

    #[Test]
    public function a_rebound_date_service_replaces_the_statics(): void
    {
        $this->registerCarbonClasses();

        $this->bindDateService(new FactoryImmutable([], 'DateCarbon'));
        $this->assertSame('DateCarbon', $this->resolve());
    }

    #[Test]
    public function an_unresolvable_facade_root_falls_back_to_the_statics(): void
    {
        $this->registerCarbonClasses();

        // No facade application: the root is null.
        Facade::setFacadeApplication(null);
        Facade::clearResolvedInstances();
        DateFactory::useClass('DateCarbon');
        $this->assertSame('DateCarbon', $this->resolve());

        // An app without the `date` service: the lookup throws.
        Facade::setFacadeApplication(new Container());
        $this->assertSame('DateCarbon', $this->resolve());
    }

    #[Test]
    public function the_interface_needs_psalm_storage(): void
    {
        // Without CarbonInterface in storage, a TNamedObject for it would break analysis.
        DateFactory::useCallable(static fn(mixed $date): mixed => $date);
        $this->assertNull(ConfiguredDateClass::type($this->codebase));

        DateFactory::useClass(CarbonImmutable::class);
        $this->assertNull(ConfiguredDateClass::type($this->codebase));
    }

    private function resolve(): string
    {
        $type = ConfiguredDateClass::type($this->codebase);
        $this->assertNotNull($type);

        return $type->value;
    }

    private function bindDateService(object $date): void
    {
        $app = new Container();
        $app->instance('date', $date);
        Facade::clearResolvedInstances();
        Facade::setFacadeApplication($app);
    }

    private function registerCarbonClasses(): void
    {
        $storage = $this->codebase->classlike_storage_provider;
        $storage->create(CarbonInterface::class);
        $storage->create('DateCarbon')->class_implements = [\strtolower(CarbonInterface::class) => CarbonInterface::class];
        $storage->create('DatePlain');
    }
}
