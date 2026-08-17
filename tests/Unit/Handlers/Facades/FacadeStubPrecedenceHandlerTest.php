<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Facades;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Facade;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\Internal\Provider\ClassLikeStorageProvider;
use Psalm\LaravelPlugin\Handlers\Facades\FacadeStubPrecedenceHandler;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Storage\MethodStorage;

#[CoversClass(FacadeStubPrecedenceHandler::class)]
final class FacadeStubPrecedenceHandlerTest extends TestCase
{
    #[\Override]
    protected function tearDown(): void
    {
        ClassLikeStorageProvider::deleteAll();
    }

    #[Test]
    public function it_removes_only_plugin_stub_conflicts_from_first_party_facades_and_descendants(): void
    {
        $provider = new ClassLikeStorageProvider();

        $cache = $provider->create(Cache::class);
        $cache->parent_classes[\strtolower(Facade::class)] = Facade::class;
        $cache->methods['remember'] = $this->stubbedMethod('/repo/stubs/common/Support/Facades/Cache.phpstub');
        $cache->methods['versioned'] = $this->stubbedMethod('/repo/stubs/13.0/Support/Facades/Cache.phpstub');
        $cache->methods['projectoverride'] = $this->stubbedMethod('/project/stubs/Cache.phpstub');
        $cache->pseudo_static_methods = [
            'remember' => new MethodStorage(),
            'versioned' => new MethodStorage(),
            'projectoverride' => new MethodStorage(),
            'get' => new MethodStorage(),
        ];

        $child = $provider->create('App\\CacheChildFacade');
        $child->parent_classes[\strtolower(Cache::class)] = Cache::class;
        $child->pseudo_static_methods = $cache->pseudo_static_methods;

        $applicationFacade = $provider->create('App\\DiagnosticFacade');
        $applicationFacade->parent_classes[\strtolower(Facade::class)] = Facade::class;
        $applicationFacade->pseudo_static_methods['remember'] = new MethodStorage();

        FacadeStubPrecedenceHandler::afterCodebasePopulated($this->eventFor($provider));

        $this->assertArrayNotHasKey('remember', $cache->pseudo_static_methods);
        $this->assertArrayNotHasKey('versioned', $cache->pseudo_static_methods);
        $this->assertArrayNotHasKey('remember', $child->pseudo_static_methods);
        $this->assertArrayNotHasKey('versioned', $child->pseudo_static_methods);

        $this->assertArrayHasKey('get', $cache->pseudo_static_methods, 'Unstubbed facade methods keep native pseudo resolution.');
        $this->assertArrayHasKey('get', $child->pseudo_static_methods);
        $this->assertArrayHasKey(
            'projectoverride',
            $cache->pseudo_static_methods,
            'Project-owned stubs are outside the plugin precedence policy.',
        );
        $this->assertArrayHasKey('remember', $applicationFacade->pseudo_static_methods);
    }

    private function stubbedMethod(string $filePath): MethodStorage
    {
        $method = new MethodStorage();
        $method->stubbed = true;

        $location = (new \ReflectionClass(CodeLocation::class))->newInstanceWithoutConstructor();
        /** @psalm-suppress InaccessibleProperty Synthetic location for storage-provenance testing. */
        $location->file_path = $filePath;

        $method->location = $location;

        return $method;
    }

    private function eventFor(ClassLikeStorageProvider $provider): AfterCodebasePopulatedEvent
    {
        $codebase = (new \ReflectionClass(Codebase::class))->newInstanceWithoutConstructor();
        $codebase->classlike_storage_provider = $provider;

        return new AfterCodebasePopulatedEvent($codebase);
    }
}
