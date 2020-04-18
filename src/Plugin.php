<?php
namespace Psalm\LaravelPlugin;

use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Engines\PhpEngine;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
use Orchestra\Testbench\Concerns\CreatesApplication;
use Psalm\Plugin\PluginEntryPointInterface;
use Psalm\Plugin\RegistrationInterface;
use SimpleXMLElement;
use function file_exists;
use function getcwd;
use const DIRECTORY_SEPARATOR;
use function unlink;
use function dirname;
use function glob;

class Plugin implements PluginEntryPointInterface
{
    use CreatesApplication;

    /** @var array<string> */
    public static $model_classes = [];

    /**
     * @return void
     */
    public function __invoke(RegistrationInterface $registration, ?SimpleXMLElement $config = null)
    {
        $app = $this->bootApp();

        $fake_filesystem = new FakeFilesystem();

        $view_factory = $this->getViewFactory($app, $fake_filesystem);

        $cache_dir = __DIR__ . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR;

        $this->ingestFacadeStubs($registration, $app, $fake_filesystem, $view_factory, $cache_dir);
        $this->ingestMetaStubs($registration, $app, $fake_filesystem, $view_factory, $cache_dir);
        $this->ingestModelStubs($registration, $app, $fake_filesystem, $cache_dir);

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
        $stubs_generator_command = new \Barryvdh\LaravelIdeHelper\Console\GeneratorCommand(
            $app['config'],
            $fake_filesystem,
            $view_factory
        );

        $stubs_generator_command->setLaravel($app);

        @unlink($cache_dir . 'stubs.php');

        $fake_filesystem->setDestination($cache_dir . 'stubs.php');

        $stubs_generator_command->run(
            new \Symfony\Component\Console\Input\ArrayInput([]),
            new \Symfony\Component\Console\Output\NullOutput()
        );

        $registration->addStubFile($cache_dir . 'stubs.php');
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

        @unlink($cache_dir . 'meta.php');

        $fake_filesystem->setDestination($cache_dir . 'meta.php');

        $meta_generator_command->run(
            new \Symfony\Component\Console\Input\ArrayInput([]),
            new \Symfony\Component\Console\Output\NullOutput()
        );
        
        $registration->addStubFile($cache_dir . 'meta.php');
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

        @unlink($cache_dir . 'models.php');

        $fake_filesystem->setDestination($cache_dir . 'models.php');

        $models_generator_command->run(
            new \Symfony\Component\Console\Input\ArrayInput([
                '--nowrite' => true
            ]),
            new \Symfony\Component\Console\Output\NullOutput()
        );

        $registration->addStubFile($cache_dir . 'models.php');

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
        $resolver->register('php', function () : PhpEngine {
            return new PhpEngine();
        });
        $finder = new FileViewFinder($fake_filesystem, [dirname($file_path) . '/../resources/views']);
        $factory = new Factory($resolver, $finder, new \Illuminate\Events\Dispatcher());
        $factory->addExtension('php', 'php');
        return $factory;
    }

    /**
     * @param \Illuminate\Foundation\Application $app
     */
    protected function getEnvironmentSetUp($app): void
    {
        $app['config']->set('app.key', 'AckfSECXIvnK5r28GVIWUAxmbBSjTsmF');
    }

    private function addOurStubs(RegistrationInterface $registration): void
    {
        foreach (glob(__DIR__ . '/Stubs/*.stubphp') as $stubFilePath) {
            $registration->addStubFile($stubFilePath);
        }
    }

    /**
     * @return \Illuminate\Foundation\Application|\Laravel\Lumen\Application
     */
    private function bootApp()
    {
        if (file_exists($applicationPath = __DIR__.'/../../../../bootstrap/app.php')) { // Applications
            $app = require $applicationPath;
        } elseif (file_exists($applicationPath = getcwd().'/bootstrap/app.php')) { // Local Dev
            $app = require $applicationPath;
        } else { // Packages
            $app = (new static)->createApplication();
        }

        if ($app instanceof \Illuminate\Contracts\Foundation\Application) {
            $app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        } elseif ($app instanceof \Laravel\Lumen\Application) {
            $app->boot();
        }

        $app->register(\Barryvdh\LaravelIdeHelper\IdeHelperServiceProvider::class);

        return $app;
    }
}
