<?php
namespace Psalm\LaravelPlugin;

use Psalm\Plugin\PluginEntryPointInterface;
use Psalm\Plugin\RegistrationInterface;
use SimpleXMLElement;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Engines\PhpEngine;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
use Orchestra\Testbench\Concerns\CreatesApplication;

class Plugin implements PluginEntryPointInterface
{
    use CreatesApplication;

    /**
     * @return void
     */
    public function __invoke(RegistrationInterface $registration, ?SimpleXMLElement $config = null)
    {
        if (file_exists($applicationPath = __DIR__.'/../../../../bootstrap/app.php')) { // Applications
            $app = require $applicationPath;
        } elseif (file_exists($applicationPath = getcwd().'/bootstrap/app.php')) { // Local Dev
            $app = require $applicationPath;
        } else { // Packages
            $app = (new self)->createApplication();
            $app->register(\Barryvdh\LaravelIdeHelper\IdeHelperServiceProvider::class);
        }

        if ($app instanceof \Illuminate\Contracts\Foundation\Application) {
            /** @var \Illuminate\Contracts\Http\Kernel $kernel */
            $kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
            $kernel->bootstrap();
        }

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

    private function getViewFactory(
        \Illuminate\Contracts\Foundation\Application $app,
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
