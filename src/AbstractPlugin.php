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

abstract class AbstractPlugin implements PluginEntryPointInterface
{
    use CreatesApplication;


    /**
     * Get and load ide provider for Laravel or Lumen Application container
     *
     * @param \Illuminate\Container\Container $app
     * @param string $ide_helper_provider
     * @return \Illuminate\Contracts\Foundation\Application|\Laravel\Lumen\Application|\Illuminate\Container\Container
     */
    abstract function loadIdeProvider($app, $ide_helper_provider);

    /**
     * @return void
     */
    public function __invoke(RegistrationInterface $registration, ?SimpleXMLElement $config = null)
    {
        $ide_helper_provider = \Barryvdh\LaravelIdeHelper\IdeHelperServiceProvider::class;

        if (file_exists($applicationPath = __DIR__.'/../../../../bootstrap/app.php')) { // Applications
            $app = require $applicationPath;
        } elseif (file_exists($applicationPath = getcwd().'/bootstrap/app.php')) { // Local Dev
            $app = require $applicationPath;
        } else { // Packages
            $app = (new static)->loadIdeProvider();
            $app->register($ide_helper_provider);
        }

        $app = $this->getApplicationInstance($app, $ide_helper_provider);

        $fake_filesystem = new FakeFilesystem();

        $view_factory = $this->getViewFactory($app, $fake_filesystem);

        $stubs_generator_command = new \Barryvdh\LaravelIdeHelper\Console\GeneratorCommand(
            $app['config'],
            $fake_filesystem,
            $view_factory
        );

        $stubs_generator_command->setLaravel($app);

        $cache_dir = __DIR__ . DIRECTORY_SEPARATOR . 'cache' . DIRECTORY_SEPARATOR;

        $fake_filesystem->setDestination($cache_dir . 'stubs.php');

        $stubs_generator_command->run(
            new \Symfony\Component\Console\Input\ArrayInput([]),
            new \Symfony\Component\Console\Output\NullOutput()
        );

        /** @psalm-suppress InvalidArgument */
        $meta_generator_command = new FakeMetaCommand(
            $fake_filesystem,
            $view_factory,
            $app['config']
        );

        $meta_generator_command->setLaravel($app);

        $fake_filesystem->setDestination($cache_dir . 'meta.php');

        $meta_generator_command->run(
            new \Symfony\Component\Console\Input\ArrayInput([]),
            new \Symfony\Component\Console\Output\NullOutput()
        );

        $registration->addStubFile($cache_dir . 'stubs.php');
        $registration->addStubFile($cache_dir . 'meta.php');

        require_once 'ReturnTypeProvider/AuthReturnTypeProvider.php';
        $registration->registerHooksFromClass(ReturnTypeProvider\AuthReturnTypeProvider::class);
        require_once 'ReturnTypeProvider/TransReturnTypeProvider.php';
        $registration->registerHooksFromClass(ReturnTypeProvider\TransReturnTypeProvider::class);
        require_once 'ReturnTypeProvider/ViewReturnTypeProvider.php';
        $registration->registerHooksFromClass(ReturnTypeProvider\ViewReturnTypeProvider::class);
        require_once 'AppInterfaceProvider.php';
        $registration->registerHooksFromClass(AppInterfaceProvider::class);
    }

    /**
     * Undocumented function
     *
     * @param \Illuminate\Contracts\Foundation\Application|\Laravel\Lumen\Application|\Illuminate\Container\Container $app
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
        // ..
    }
}
