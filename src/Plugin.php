<?php
namespace Psalm\LaravelPlugin;

use Psalm\LaravelPlugin\Handlers\Application\ContainerHandler;
use Psalm\LaravelPlugin\Handlers\Application\OffsetHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelMethodHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelPropertyHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\RelationsMethodHandler;
use Psalm\LaravelPlugin\Handlers\Helpers\PathHandler;
use Psalm\LaravelPlugin\Handlers\Helpers\RedirectHandler;
use Psalm\LaravelPlugin\Handlers\Helpers\TransHandler;
use Psalm\LaravelPlugin\Handlers\Helpers\UrlHandler;
use Psalm\LaravelPlugin\Handlers\Helpers\ViewHandler;
use Psalm\LaravelPlugin\Providers\FacadeStubProvider;
use Psalm\LaravelPlugin\Providers\ModelStubProvider;
use Psalm\LaravelPlugin\Providers\ApplicationProvider;
use Psalm\Plugin\PluginEntryPointInterface;
use Psalm\Plugin\RegistrationInterface;
use SimpleXMLElement;
use function dirname;
use function glob;

class Plugin implements PluginEntryPointInterface
{

    public function __invoke(RegistrationInterface $registration, ?SimpleXMLElement $config = null) : void
    {
        try {
            ApplicationProvider::bootApp();
            $this->generateStubFiles();
        } catch (\Throwable $t) {
            return;
        }

        $this->registerHandlers($registration);
        $this->registerStubs($registration);
    }

    private function registerStubs(RegistrationInterface $registration): void
    {
        foreach (glob(dirname(__DIR__) . '/stubs/*.stubphp') as $stubFilePath) {
            $registration->addStubFile($stubFilePath);
        }

        $registration->addStubFile(FacadeStubProvider::getStubFileLocation());
        $registration->addStubFile(ModelStubProvider::getStubFileLocation());
    }

    /**
     * @param \Psalm\Plugin\RegistrationInterface $registration
     */
    private function registerHandlers(RegistrationInterface $registration): void
    {
        require_once 'Handlers/Application/ContainerHandler.php';
        $registration->registerHooksFromClass(ContainerHandler::class);
        require_once 'Handlers/Application/OffsetHandler.php';
        $registration->registerHooksFromClass(OffsetHandler::class);
        require_once 'Handlers/Eloquent/ModelPropertyHandler.php';
        $registration->registerHooksFromClass(ModelPropertyHandler::class);
        require_once 'Handlers/Eloquent/RelationsMethodHandler.php';
        $registration->registerHooksFromClass(RelationsMethodHandler::class);
        require_once 'Handlers/Eloquent/ModelMethodHandler.php';
        $registration->registerHooksFromClass(ModelMethodHandler::class);
        require_once 'Handlers/Helpers/ViewHandler.php';
        $registration->registerHooksFromClass(ViewHandler::class);
        require_once 'Handlers/Helpers/PathHandler.php';
        $registration->registerHooksFromClass(PathHandler::class);
        require_once 'Handlers/Helpers/UrlHandler.php';
        $registration->registerHooksFromClass(UrlHandler::class);
        require_once 'Handlers/Helpers/TransHandler.php';
        $registration->registerHooksFromClass(TransHandler::class);
        require_once 'Handlers/Helpers/RedirectHandler.php';
        $registration->registerHooksFromClass(RedirectHandler::class);
    }

    private function generateStubFiles(): void
    {
        FacadeStubProvider::generateStubFile();
        ModelStubProvider::generateStubFile();
    }
}
