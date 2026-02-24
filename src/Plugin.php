<?php

declare(strict_types=1);

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
use Psalm\PluginRegistrationSocket;
use Psalm\Progress\DefaultProgress;
use SimpleXMLElement;
use Symfony\Component\Finder\Finder;

use function array_merge;
use function dirname;
use function explode;
use function glob;
use function is_string;
use function sprintf;
use function urlencode;

/**
 * @psalm-suppress UnusedClass
 * @internal
 */
final class Plugin implements PluginEntryPointInterface
{
    /** @inheritDoc */
    #[\Override]
    public function __invoke(RegistrationInterface $registration, ?SimpleXMLElement $config = null): void
    {
        $failOnInternalError = ((string) $config?->failOnInternalError) === 'true';
        $output = new DefaultProgress();

        // $registration->codebase is available/public from Psalm v6.7
        // see https://github.com/vimeo/psalm/pull/11297 and https://github.com/vimeo/psalm/releases/tag/6.7.0
        if ($registration instanceof PluginRegistrationSocket && isset($registration->codebase)) {
            $output = $registration->codebase->progress;
        }

        try {
            ApplicationProvider::bootApp();
        } catch (\Throwable $throwable) {
            $output->warning("Laravel plugin error on booting Laravel app: “{$throwable->getMessage()}”");
            $output->warning('Laravel plugin has been disabled for this run, please report about this issue: ' . $this->generateReportIssueUrl($throwable));

            if ($failOnInternalError) {
                throw $throwable;
            }

            return;
        }

        try {
            $this->generateStubFiles();
        } catch (\Throwable $throwable) {
            $output->warning("Laravel plugin error on generating stub files: “{$throwable->getMessage()}”");
            $output->warning('Laravel plugin has been disabled for this run, please report about this issue: ' . $this->generateReportIssueUrl($throwable));

            if ($failOnInternalError) {
                throw $throwable;
            }

            return;
        }

        $this->registerHandlers($registration);
        $this->registerStubs($registration);
    }

    /** @return list<string> */
    private function getCommonStubs(): array
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
    private function getTaintAnalysisStubs(): array
    {
        return [
            ...glob(dirname(__DIR__) . '/stubs/common/TaintAnalysis/Http/*.stubphp') ?: []
        ];
    }

    /** @return list<string> */
    private function getStubsForVersion(string $version): array
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

    private function generateReportIssueUrl(\Throwable $throwable): string
    {
        return sprintf(
            'https://github.com/psalm/psalm-plugin-laravel/issues/new?template=bug_report.md&title=%s&body=%s',
            urlencode("Error on generating stub files: {$throwable->getMessage()}"),
            urlencode("```\n{$throwable->__toString()}\n```")
        );
    }
}
