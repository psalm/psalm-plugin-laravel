<?php
namespace Psalm\LaravelPlugin;

use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Engines\PhpEngine;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
use Psalm\LaravelPlugin\ReturnTypeProvider\ModelReturnTypeProvider;
use Psalm\LaravelPlugin\ReturnTypeProvider\PathHelpersReturnTypeProvider;
use Psalm\LaravelPlugin\ReturnTypeProvider\RelationReturnTypeProvider;
use Psalm\LaravelPlugin\ReturnTypeProvider\UrlReturnTypeProvider;
use Psalm\Plugin\PluginEntryPointInterface;
use Psalm\Plugin\RegistrationInterface;
use SimpleXMLElement;
use const DIRECTORY_SEPARATOR;
use function unlink;
use function dirname;
use function glob;

class Plugin implements PluginEntryPointInterface
{

    /** @var array<string> */
    public static $model_classes = [];

    public function __invoke(RegistrationInterface $registration, ?SimpleXMLElement $config = null) : void
    {
        try {
            $app = ApplicationHelper::bootApp();
            $fake_filesystem = new FakeFilesystem();
            $view_factory = $this->getViewFactory($app, $fake_filesystem);
            $cache_dir = __DIR__ . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR;

            $this->ingestFacadeStubs($registration, $app, $fake_filesystem, $view_factory, $cache_dir);
            $this->ingestMetaStubs($registration, $app, $fake_filesystem, $view_factory, $cache_dir);
            $this->ingestModelStubs($registration, $app, $fake_filesystem, $cache_dir);
        } catch (\Throwable $t) {
            return;
        }

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
        require_once 'AppInterfaceProvider.php';
        $registration->registerHooksFromClass(AppInterfaceProvider::class);
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

        $this->addOurStubs($registration);
    }

    /**
     * @param \Illuminate\Foundation\Application|\Laravel\Lumen\Application $app
     * @param \Illuminate\View\Factory $view_factory
     */
    private function ingestFacadeStubs(
        RegistrationInterface $registration,
        $app,
        \Illuminate\Filesystem\Filesystem $fake_filesystem,
        $view_factory,
        string $cache_dir
    ) : void {
        /** @var \Illuminate\Config\Repository $config */
        $config = $app['config'];

        // The \Eloquent mixin has less specific return types than our custom plugin can determine, so we unset it here
        // to not taint our analysis
        if ($ideHelperExtra = $config->get('ide-helper.extra')) {
            if (isset($ideHelperExtra['Eloquent'])) {
                unset($ideHelperExtra['Eloquent']);
                $config->set('ide-helper.extra', $ideHelperExtra);
            }
        }

        $stubs_generator_command = new \Barryvdh\LaravelIdeHelper\Console\GeneratorCommand(
            $config,
            $fake_filesystem,
            $view_factory
        );

        $stubs_generator_command->setLaravel($app);

        @unlink($cache_dir . 'stubs.stubphp');

        $fake_filesystem->setDestination($cache_dir . 'stubs.stubphp');

        $stubs_generator_command->run(
            new \Symfony\Component\Console\Input\ArrayInput([]),
            new \Symfony\Component\Console\Output\NullOutput()
        );

        $registration->addStubFile($cache_dir . 'stubs.stubphp');
    }

    /**
     * @param \Illuminate\Foundation\Application|\Laravel\Lumen\Application $app
     * @param \Illuminate\View\Factory $view_factory
     */
    private function ingestMetaStubs(
        RegistrationInterface $registration,
        $app,
        \Illuminate\Filesystem\Filesystem $fake_filesystem,
        $view_factory,
        string $cache_dir
    ) : void {
        /** @psalm-suppress InvalidArgument */
        $meta_generator_command = new FakeMetaCommand(
            $fake_filesystem,
            $view_factory,
            $app['config']
        );

        $meta_generator_command->setLaravel($app);

        @unlink($cache_dir . 'meta.stubphp');

        $fake_filesystem->setDestination($cache_dir . 'meta.stubphp');

        $meta_generator_command->run(
            new \Symfony\Component\Console\Input\ArrayInput([]),
            new \Symfony\Component\Console\Output\NullOutput()
        );
        
        $registration->addStubFile($cache_dir . 'meta.stubphp');
    }

    /**
     * @param \Illuminate\Foundation\Application|\Laravel\Lumen\Application $app
     */
    private function ingestModelStubs(
        RegistrationInterface $registration,
        $app,
        \Illuminate\Filesystem\Filesystem $fake_filesystem,
        string $cache_dir
    ) : void {
        $migrations_folder = dirname(__DIR__, 4) . '/database/migrations/';

        $project_analyzer = \Psalm\Internal\Analyzer\ProjectAnalyzer::getInstance();
        $codebase = $project_analyzer->getCodebase();

        $schema_aggregator = new SchemaAggregator();

        foreach (glob($migrations_folder . '*.php') as $file) {
            //echo $file . "\n";
            $schema_aggregator->addStatements($codebase->getStatementsForFile($file));
        }

        $models_generator_command = new FakeModelsCommand(
            $fake_filesystem,
            $schema_aggregator
        );

        $models_generator_command->setLaravel($app);

        @unlink($cache_dir . 'models.stubphp');

        $fake_filesystem->setDestination($cache_dir . 'models.stubphp');

        $models_generator_command->run(
            new \Symfony\Component\Console\Input\ArrayInput([
                '--nowrite' => true
            ]),
            new \Symfony\Component\Console\Output\NullOutput()
        );

        $registration->addStubFile($cache_dir . 'models.stubphp');

        self::$model_classes = $models_generator_command->getModels();
    }

    /**
     * @param \Illuminate\Foundation\Application|\Laravel\Lumen\Application $app
     * @param FakeFilesystem $fake_filesystem
     * @return Factory
     */
    private function getViewFactory(
        \Illuminate\Container\Container $app,
        FakeFilesystem $fake_filesystem
    ) : Factory {
        $service_helper_reflection = new \ReflectionClass(\Barryvdh\LaravelIdeHelper\IdeHelperServiceProvider::class);

        $file_path = $service_helper_reflection->getFileName();

        if (!$file_path) {
            throw new \UnexpectedValueException('Service helper should have a file path');
        }

        $resolver = new EngineResolver();
        $resolver->register('php', function () use ($fake_filesystem) : PhpEngine {
            return new PhpEngine($fake_filesystem);
        });
        $finder = new FileViewFinder($fake_filesystem, [dirname($file_path) . '/../resources/views']);
        $factory = new Factory($resolver, $finder, new \Illuminate\Events\Dispatcher());
        $factory->addExtension('php', 'php');
        return $factory;
    }

    private function addOurStubs(RegistrationInterface $registration): void
    {
        foreach (glob(__DIR__ . '/Stubs/*.stubphp') as $stubFilePath) {
            $registration->addStubFile($stubFilePath);
        }
    }
}
