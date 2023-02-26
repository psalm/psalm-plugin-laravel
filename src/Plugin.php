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
use Psalm\LaravelPlugin\Handlers\Helpers\TransHandler;
use Psalm\LaravelPlugin\Handlers\SuppressHandler;
use Psalm\LaravelPlugin\Providers\ApplicationProvider;
use Psalm\LaravelPlugin\Providers\FacadeStubProvider;
use Psalm\LaravelPlugin\Providers\ModelStubProvider;
use Psalm\Plugin\PluginEntryPointInterface;
use Psalm\Plugin\RegistrationInterface;
use SimpleXMLElement;

use function array_merge;
use function dirname;
use function fwrite;
use function explode;
use function glob;

/**
 * @psalm-suppress UnusedClass
 * @internal
 */
class Plugin implements PluginEntryPointInterface
{
    public function __invoke(RegistrationInterface $registration, ?SimpleXMLElement $config = null): void
    {
        try {
            ApplicationProvider::bootApp();
            $this->generateStubFiles();
        } catch (\Throwable $t) {
            fwrite(\STDERR, "Laravel plugin error: “{$t->getMessage()}”\n");
            return;
        }

        $this->registerHandlers($registration);
        $this->registerStubs($registration);
    }

    /** @return array<array-key, string> */
    protected function getCommonStubs(): array
    {
        return array_merge(
            glob(dirname(__DIR__) . '/stubs/Collections/*.stubphp'),
            glob(dirname(__DIR__) . '/stubs/Contracts/*.stubphp'),
            glob(dirname(__DIR__) . '/stubs/Database/*.stubphp'),
            glob(dirname(__DIR__) . '/stubs/Foundation/*.stubphp'),
            glob(dirname(__DIR__) . '/stubs/Http/*.stubphp'),
            glob(dirname(__DIR__) . '/stubs/legacy-factories/*.stubphp'),
            glob(dirname(__DIR__) . '/stubs/Lumen/*.stubphp'),
            glob(dirname(__DIR__) . '/stubs/Pagination/*.stubphp'),
            glob(dirname(__DIR__) . '/stubs/Support/*.stubphp'),
        );
    }

    /** @return array<array-key, string> */
    protected function getTaintAnalysisStubs(): array
    {
        return array_merge(
            glob(dirname(__DIR__) . '/stubs/TaintAnalysis/Http/*.stubphp'),
        );
    }

    /** @return array<array-key, string> */
    protected function getStubsForVersion(string $version): array
    {
        [$majorVersion] = explode('.', $version);

        return array_merge(
            glob(dirname(__DIR__) . '/stubs/' . $majorVersion . '/*.stubphp'),
            glob(dirname(__DIR__) . '/stubs/' . $majorVersion . '/**/*.stubphp'),
        );
    }

    private function registerStubs(RegistrationInterface $registration): void
    {
        $stubs = array_merge(
            $this->getCommonStubs(),
            $this->getStubsForVersion(Application::VERSION),
            $this->getTaintAnalysisStubs(),
        );

        foreach ($stubs as $stubFilePath) {
            $registration->addStubFile($stubFilePath);
        }

        $registration->addStubFile(FacadeStubProvider::getStubFileLocation());
        $registration->addStubFile(ModelStubProvider::getStubFileLocation());
    }

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
