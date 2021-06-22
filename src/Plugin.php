<?php
namespace Psalm\LaravelPlugin;

use Psalm\LaravelPlugin\Handlers\Application\ContainerHandler;
use Psalm\LaravelPlugin\Handlers\Application\OffsetHandler;
use Psalm\LaravelPlugin\Providers\FacadeStubProvider;
use Psalm\LaravelPlugin\Providers\MetaStubProvider;
use Psalm\LaravelPlugin\Providers\ModelStubProvider;
use Psalm\LaravelPlugin\ReturnTypeProvider\ModelReturnTypeProvider;
use Psalm\LaravelPlugin\ReturnTypeProvider\PathHelpersReturnTypeProvider;
use Psalm\LaravelPlugin\ReturnTypeProvider\RelationReturnTypeProvider;
use Psalm\LaravelPlugin\ReturnTypeProvider\UrlReturnTypeProvider;
use Psalm\LaravelPlugin\Providers\ApplicationProvider;
use Psalm\Plugin\PluginEntryPointInterface;
use Psalm\Plugin\RegistrationInterface;
use SimpleXMLElement;
use function dirname;
use function glob;

class Plugin implements PluginEntryPointInterface
{

    /** @var array<string> */
    public static $model_classes = [];

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
        $registration->addStubFile(MetaStubProvider::getStubFileLocation());
        $registration->addStubFile(ModelStubProvider::getStubFileLocation());
    }

    /**
     * @param \Psalm\Plugin\RegistrationInterface $registration
     */
    private function registerHandlers(RegistrationInterface $registration): void
    {
        require_once 'ReturnTypeProvider/AuthReturnTypeProvider.php';
        $registration->registerHooksFromClass(ReturnTypeProvider\AuthReturnTypeProvider::class);
        require_once 'ReturnTypeProvider/TransReturnTypeProvider.php';
        $registration->registerHooksFromClass(ReturnTypeProvider\TransReturnTypeProvider::class);
        require_once 'ReturnTypeProvider/RedirectReturnTypeProvider.php';
        $registration->registerHooksFromClass(ReturnTypeProvider\RedirectReturnTypeProvider::class);
        require_once 'ReturnTypeProvider/ViewReturnTypeProvider.php';
        $registration->registerHooksFromClass(ReturnTypeProvider\ViewReturnTypeProvider::class);
        require_once 'ReturnTypeProvider/AppReturnTypeProvider.php';
        $registration->registerHooksFromClass(ReturnTypeProvider\AppReturnTypeProvider::class);
        require_once 'Handlers/Application/ContainerHandler.php';
        $registration->registerHooksFromClass(ContainerHandler::class);
        require_once 'Handlers/Application/OffsetHandler.php';
        $registration->registerHooksFromClass(OffsetHandler::class);
        require_once 'PropertyProvider/ModelPropertyProvider.php';
        $registration->registerHooksFromClass(PropertyProvider\ModelPropertyProvider::class);
        require_once 'ReturnTypeProvider/UrlReturnTypeProvider.php';
        $registration->registerHooksFromClass(UrlReturnTypeProvider::class);
        require_once 'ReturnTypeProvider/ModelReturnTypeProvider.php';
        $registration->registerHooksFromClass(ModelReturnTypeProvider::class);
        require_once 'ReturnTypeProvider/RelationReturnTypeProvider.php';
        $registration->registerHooksFromClass(RelationReturnTypeProvider::class);
        require_once 'ReturnTypeProvider/PathHelpersReturnTypeProvider.php';
        $registration->registerHooksFromClass(PathHelpersReturnTypeProvider::class);
    }

    private function generateStubFiles(): void
    {
        FacadeStubProvider::generateStubFile();
        MetaStubProvider::generateStubFile();
        ModelStubProvider::generateStubFile();
    }
}
