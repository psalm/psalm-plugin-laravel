<?php

namespace Psalm\LaravelPlugin;

use Illuminate\Foundation\Application;
use Psalm\LaravelPlugin\Handlers\Application\ContainerHandler;
use Psalm\LaravelPlugin\Handlers\Application\OffsetHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelMethodHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelPropertyAccessorHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelRelationshipPropertyHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\RelationsMethodHandler;
use Psalm\LaravelPlugin\Handlers\Helpers\CacheHandler;
use Psalm\LaravelPlugin\Handlers\Helpers\PathHandler;
use Psalm\LaravelPlugin\Handlers\Helpers\RedirectHandler;
use Psalm\LaravelPlugin\Handlers\Helpers\TransHandler;
use Psalm\LaravelPlugin\Handlers\Helpers\UrlHandler;
use Psalm\LaravelPlugin\Handlers\Helpers\ViewHandler;
use Psalm\LaravelPlugin\Handlers\SuppressHandler;
use Psalm\LaravelPlugin\Providers\ApplicationProvider;
use Psalm\LaravelPlugin\Providers\FacadeStubProvider;
use Psalm\LaravelPlugin\Providers\ModelStubProvider;
use Psalm\Plugin\PluginEntryPointInterface;
use Psalm\Plugin\RegistrationInterface;
use SimpleXMLElement;
use Throwable;

use function array_merge;
use function dirname;
use function explode;
use function glob;

/**
 * @psalm-suppress UnusedClass
 */
class Plugin implements PluginEntryPointInterface
{
    public function __invoke(RegistrationInterface $registration, ?SimpleXMLElement $config = null): void
    {
        try {
            ApplicationProvider::bootApp();
            $this->generateStubFiles();
        } catch (Throwable $t) {
            return;
        }

        $this->registerHandlers($registration);
        $this->registerStubs($registration);
    }

    protected function getCommonStubs(): array
    {
        return array_merge(
            glob(dirname(__DIR__) . '/stubs/Contracts/*.stubphp'),
            glob(dirname(__DIR__) . '/stubs/*.stubphp')
        );
    }

    protected function getStubsForVersion(string $version): array
    {
        [$majorVersion] = explode('.', $version);

        return glob(dirname(__DIR__) . '/stubs/' . $majorVersion . '/*.stubphp');
    }

    private function registerStubs(RegistrationInterface $registration): void
    {
        $stubs = array_merge(
            $this->getCommonStubs(),
            $this->getStubsForVersion(Application::VERSION),
        );

        foreach ($stubs as $stubFilePath) {
            $registration->addStubFile($stubFilePath);
        }

        $registration->addStubFile(FacadeStubProvider::getStubFileLocation());
        $registration->addStubFile(ModelStubProvider::getStubFileLocation());
    }

    /**
     * @param RegistrationInterface $registration
     */
    private function registerHandlers(RegistrationInterface $registration): void
    {
        require_once 'Handlers/Application/ContainerHandler.php';
        $registration->registerHooksFromClass(ContainerHandler::class);
        require_once 'Handlers/Application/OffsetHandler.php';
        $registration->registerHooksFromClass(OffsetHandler::class);

        require_once 'Handlers/Eloquent/ModelRelationshipPropertyHandler.php';
        $registration->registerHooksFromClass(ModelRelationshipPropertyHandler::class);
        require_once 'Handlers/Eloquent/ModelPropertyAccessorHandler.php';
        $registration->registerHooksFromClass(ModelPropertyAccessorHandler::class);
        require_once 'Handlers/Eloquent/RelationsMethodHandler.php';
        $registration->registerHooksFromClass(RelationsMethodHandler::class);
        require_once 'Handlers/Eloquent/ModelMethodHandler.php';
        $registration->registerHooksFromClass(ModelMethodHandler::class);

        require_once 'Handlers/Helpers/CacheHandler.php';
        $registration->registerHooksFromClass(CacheHandler::class);
        require_once 'Handlers/Helpers/PathHandler.php';
        $registration->registerHooksFromClass(PathHandler::class);
        require_once 'Handlers/Helpers/TransHandler.php';
        $registration->registerHooksFromClass(TransHandler::class);

        require_once 'Handlers/SuppressHandler.php';
        $registration->registerHooksFromClass(SuppressHandler::class);
    }

    private function generateStubFiles(): void
    {
        FacadeStubProvider::generateStubFile();
        ModelStubProvider::generateStubFile();
    }
}
