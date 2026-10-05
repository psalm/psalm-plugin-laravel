<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Application;

use PhpParser\Node\Arg;
use PhpParser\Node\Scalar\String_;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Codebase;
use Psalm\Internal\Codebase\ClassLikes;
use Psalm\Internal\Provider\ClassLikeStorageProvider;
use Psalm\LaravelPlugin\Bootstrap\ApplicationProvider;
use Psalm\LaravelPlugin\Handlers\Application\ContainerResolver;
use Psalm\NodeTypeProvider;
use Psalm\Type\Atomic\TLiteralString;
use Psalm\Type\Union;
use Tests\Psalm\LaravelPlugin\Unit\Handlers\Application\Fixtures\ThrowsOnLoadService;
use Tests\Psalm\LaravelPlugin\Unit\Util\Ast\Concerns\InitializesPsalmConfigSingleton;

/**
 * The resolver names a class from Psalm's storage instead of autoloading it (a load-time deprecation
 * there would crash the run, #1652): `app(Foo::class)` whose binding throws before Foo is loaded, and a
 * binding that resolves to a class-name string. The classes below have storage but no loadable file, so
 * only the storage path can name them; ThrowsOnLoadService throws if anything autoloads it.
 */
#[CoversClass(ContainerResolver::class)]
final class ContainerResolverTest extends TestCase
{
    use InitializesPsalmConfigSingleton;

    private const GATEWAY = 'Tests\\Psalm\\LaravelPlugin\\Unit\\Handlers\\Application\\NeverLoadedGateway';

    private const GATEWAY_CONTRACT = 'Tests\\Psalm\\LaravelPlugin\\Unit\\Handlers\\Application\\NeverLoadedGatewayContract';

    private const UNSCANNED = 'Tests\\Psalm\\LaravelPlugin\\Unit\\Handlers\\Application\\NeverScannedGateway';

    private const SERVICE_CLASS_BINDING = 'service.class';

    private Codebase $codebase;

    #[\Override]
    protected function setUp(): void
    {
        ApplicationProvider::bootApp();
        ContainerResolver::reset();

        $storageProvider = new ClassLikeStorageProvider();
        $storageProvider->create(self::GATEWAY)->abstract = true;
        $storageProvider->create(self::GATEWAY_CONTRACT)->is_interface = true;
        $storageProvider->create(ThrowsOnLoadService::class);

        $this->codebase = (new \ReflectionClass(Codebase::class))->newInstanceWithoutConstructor();
        $this->codebase->classlike_storage_provider = $storageProvider;
        $this->codebase->classlikes = (new \ReflectionClass(ClassLikes::class))->newInstanceWithoutConstructor();

        $app = ApplicationProvider::getApp();
        foreach ([self::GATEWAY, self::GATEWAY_CONTRACT, self::UNSCANNED] as $abstract) {
            $app->bind($abstract, static fn(): never => throw new \RuntimeException('stripe key missing'));
        }

        $app->bind(self::SERVICE_CLASS_BINDING, static fn(): string => ThrowsOnLoadService::class);
    }

    #[\Override]
    protected function tearDown(): void
    {
        $app = ApplicationProvider::getApp();
        $storageProvider = new ClassLikeStorageProvider();
        foreach ([self::GATEWAY, self::GATEWAY_CONTRACT, self::UNSCANNED, self::SERVICE_CLASS_BINDING] as $abstract) {
            $app->offsetUnset($abstract);
        }

        foreach ([self::GATEWAY, self::GATEWAY_CONTRACT, ThrowsOnLoadService::class] as $class) {
            $storageProvider->remove($class);
        }

        ContainerResolver::reset();
    }

    #[Test]
    public function it_names_an_unloaded_class_whose_binding_throws(): void
    {
        $this->assertSame(self::GATEWAY, $this->resolve(self::GATEWAY)?->getId());
    }

    #[Test]
    public function it_declines_an_interface_whose_binding_throws(): void
    {
        $this->assertNull($this->resolve(self::GATEWAY_CONTRACT));
    }

    #[Test]
    public function it_declines_a_class_psalm_never_scanned(): void
    {
        $this->assertNull($this->resolve(self::UNSCANNED));
    }

    #[Test]
    public function it_names_an_unloaded_class_written_with_a_leading_backslash(): void
    {
        $this->assertSame(self::GATEWAY, $this->resolve('\\' . self::GATEWAY)?->getId());
    }

    #[Test]
    public function it_names_a_class_string_binding_without_loading_it(): void
    {
        $this->assertSame(ThrowsOnLoadService::class, $this->resolve(self::SERVICE_CLASS_BINDING)?->getId());
    }

    private function resolve(string $abstract): ?Union
    {
        $arg = new Arg(new String_($abstract));
        $nodeTypeProvider = new class implements NodeTypeProvider {
            /** @var \SplObjectStorage<\PhpParser\NodeAbstract, Union> */
            private \SplObjectStorage $types;

            public function __construct()
            {
                $this->types = new \SplObjectStorage();
            }

            #[\Override]
            public function setType(\PhpParser\NodeAbstract $node, Union $type): void
            {
                $this->types[$node] = $type;
            }

            #[\Override]
            public function getType(\PhpParser\NodeAbstract $node): ?Union
            {
                return $this->types->offsetExists($node) ? $this->types[$node] : null;
            }
        };
        $nodeTypeProvider->setType($arg->value, new Union([TLiteralString::make($abstract)]));

        return ContainerResolver::resolvePsalmTypeFromApplicationContainerViaArgs($nodeTypeProvider, [$arg], $this->codebase);
    }
}
