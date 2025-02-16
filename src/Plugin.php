<?php

namespace Psalm\LaravelPlugin;

use Illuminate\Foundation\Application;
use Psalm\LaravelPlugin\Handlers\Application\ContainerHandler;
use Psalm\LaravelPlugin\Handlers\Application\OffsetHandler;
use Psalm\LaravelPlugin\Handlers\Auth\AuthHandler;
use Psalm\LaravelPlugin\Handlers\Auth\GuardHandler;
use Psalm\LaravelPlugin\Handlers\Auth\RequestHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelMethodHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelPropertyAccessorHandler;
use Psalm\LaravelPlugin\Handlers\Eloquent\ModelFactoryTypeProvider;
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
use Symfony\Component\Finder\Finder;

use function array_merge;
use function dirname;
use function fwrite;
use function explode;
use function glob;
use function is_string;

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
        } catch (\Throwable $throwable) {
            $failOnInternalError = ((string) $config?->failOnInternalError) === 'true';
            if ($failOnInternalError) {
                throw $throwable;
            }

            fwrite(\STDERR, "\nLaravel plugin error on generating stub files: \"{$throwable->getMessage()}\"\n");
            return;
        }

        $this->registerHandlers($registration);
        $this->registerStubs($registration);
    }

    /** @return list<string> */
    protected function getCommonStubs(): array
    {
        $stubFilepaths = [];

        $basePath = dirname(__DIR__) . \DIRECTORY_SEPARATOR . 'stubs' . \DIRECTORY_SEPARATOR . 'common';

        $stubFiles = Finder::create()->files()->name('*.stubphp')->in($basePath);

        foreach ($stubFiles as $stubFile) {
            $stubFilepath = $stubFile->getRealPath();
            if (is_string($stubFilepath)) {
                $stubFilepaths[] = $stubFilepath;
            }
        }

        return $stubFilepaths;
    }

    /** @return list<string> */
    protected function getTaintAnalysisStubs(): array
    {
        return [
            ...glob(dirname(__DIR__) . '/stubs/common/TaintAnalysis/Http/*.stubphp') ?: []
        ];
    }

    /** @return list<string> */
    protected function getStubsForVersion(string $version): array
    {
        [$majorVersion] = explode('.', $version);

        return [
            ...glob(dirname(__DIR__) . '/stubs/' . $majorVersion . '/*.stubphp') ?: [],
            ...glob(dirname(__DIR__) . '/stubs/' . $majorVersion . '/**/*.stubphp') ?: [],
        ];
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
        require_once __DIR__ . '/Handlers/Application/ContainerHandler.php';
        $registration->registerHooksFromClass(ContainerHandler::class);
        require_once __DIR__ . '/Handlers/Application/OffsetHandler.php';
        $registration->registerHooksFromClass(OffsetHandler::class);

        require_once __DIR__ . '/Handlers/Auth/AuthHandler.php';
        $registration->registerHooksFromClass(AuthHandler::class);
        require_once __DIR__ . '/Handlers/Auth/GuardHandler.php';
        $registration->registerHooksFromClass(GuardHandler::class);
        require_once __DIR__ . '/Handlers/Auth/RequestHandler.php';
        $registration->registerHooksFromClass(RequestHandler::class);

        require_once __DIR__ . '/Handlers/Eloquent/ModelRelationshipPropertyHandler.php';
        $registration->registerHooksFromClass(ModelRelationshipPropertyHandler::class);
        require_once __DIR__ . '/Handlers/Eloquent/ModelFactoryTypeProvider.php';
        $registration->registerHooksFromClass(ModelFactoryTypeProvider::class);
        require_once __DIR__ . '/Handlers/Eloquent/ModelPropertyAccessorHandler.php';
        $registration->registerHooksFromClass(ModelPropertyAccessorHandler::class);
        require_once __DIR__ . '/Handlers/Eloquent/RelationsMethodHandler.php';
        $registration->registerHooksFromClass(RelationsMethodHandler::class);
        require_once __DIR__ . '/Handlers/Eloquent/ModelMethodHandler.php';
        $registration->registerHooksFromClass(ModelMethodHandler::class);

        require_once __DIR__ . '/Handlers/Helpers/CacheHandler.php';
        $registration->registerHooksFromClass(CacheHandler::class);
        require_once __DIR__ . '/Handlers/Helpers/PathHandler.php';
        $registration->registerHooksFromClass(PathHandler::class);
        require_once __DIR__ . '/Handlers/Helpers/TransHandler.php';
        $registration->registerHooksFromClass(TransHandler::class);

        require_once __DIR__ . '/Handlers/SuppressHandler.php';
        $registration->registerHooksFromClass(SuppressHandler::class);
    }

    private function generateStubFiles(): void
    {
        FacadeStubProvider::generateStubFile();
        ModelStubProvider::generateStubFile();
    }
}
