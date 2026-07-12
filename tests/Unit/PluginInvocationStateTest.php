<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Engines\EngineResolver;
use Illuminate\View\Engines\PhpEngine;
use Illuminate\View\Factory;
use Illuminate\View\FileViewFinder;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\CodeLocation;
use Psalm\Config;
use Psalm\Context;
use Psalm\LaravelPlugin\Bootstrap\ApplicationProvider;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\RelationResolver;
use Psalm\LaravelPlugin\Handlers\Translations\TranslationKeyHandler;
use Psalm\LaravelPlugin\Handlers\Views\MissingViewHandler;
use Psalm\LaravelPlugin\Plugin;
use Psalm\LaravelPlugin\Stubs\FacadeMapProvider;
use Psalm\Plugin\EventHandler\Event\FunctionReturnTypeProviderEvent;
use Psalm\Plugin\RegistrationInterface;
use Psalm\StatementsSource;
use Psalm\Type\Union;

#[CoversClass(Plugin::class)]
final class PluginInvocationStateTest extends TestCase
{
    private string $originalCwd;

    /** @var list<string> */
    private array $roots = [];

    protected function setUp(): void
    {
        parent::setUp();

        $cwd = \getcwd();
        \assert(\is_string($cwd));
        $this->originalCwd = $cwd;
        Config::loadFromXML($cwd, '<?xml version="1.0"?><psalm xmlns="https://getpsalm.org/schema/config" />');
        ApplicationProvider::reset();
    }

    protected function tearDown(): void
    {
        ApplicationProvider::reset();
        \chdir($this->originalCwd);

        foreach ($this->roots as $root) {
            $this->removeDirectory($root);
        }

        (new \ReflectionClass(Config::class))->getProperty('instance')->setValue(null, null);

        parent::tearDown();
    }

    #[Test]
    public function plugin_resets_state_between_real_application_invocations(): void
    {
        $firstRoot = $this->makeApplicationRoot(
            viewBinding: '\\' . InvocationStateViewFactory::class,
            bindTranslator: true,
            alias: 'InvocationStateViewAlias',
        );
        $secondRoot = $this->makeApplicationRoot(
            viewBinding: '\\' . InvocationStateUnsupportedViewFactory::class,
            bindTranslator: false,
            alias: null,
        );
        $plugin = new Plugin();

        \chdir($firstRoot);
        $plugin($this->createStub(RegistrationInterface::class), new \SimpleXMLElement(
            '<plugin><modelProperties columnFallback="none" /><findMissingViews value="true" /><findMissingTranslations value="true" /></plugin>',
        ));

        $this->assertInstanceOf(InvocationStateViewFactory::class, ApplicationProvider::getApp()->make('view'));
        $this->assertTrue(ApplicationProvider::getApp()->bound('translator'));
        $this->assertContains(
            'InvocationStateViewAlias',
            FacadeMapProvider::getFacadeClasses(InvocationStateViewFactory::class),
        );
        (new \ReflectionProperty(RelationResolver::class, 'methodExistsCache'))->setValue(null, ['App\\Models\\Post::comments' => true]);
        (new \ReflectionProperty(RelationResolver::class, 'relatedModelCache'))->setValue(null, ['App\\Models\\Post::comments' => 'App\\Models\\Comment']);

        \chdir($secondRoot);
        $plugin($this->createStub(RegistrationInterface::class), new \SimpleXMLElement(
            '<plugin><modelProperties columnFallback="none" /><findMissingViews value="false" /><findMissingTranslations value="true" /></plugin>',
        ));

        $this->assertNull($this->functionReturn('view', 'only-in-first-application'));
        $this->assertNull((new \ReflectionProperty(TranslationKeyHandler::class, 'translator'))->getValue());
        $this->assertInstanceOf(InvocationStateUnsupportedViewFactory::class, ApplicationProvider::getApp()->make('view'));
        $this->assertSame([], (new \ReflectionProperty(RelationResolver::class, 'methodExistsCache'))->getValue());
        $this->assertSame([], (new \ReflectionProperty(RelationResolver::class, 'relatedModelCache'))->getValue());
        $this->assertNotContains(
            'InvocationStateViewAlias',
            FacadeMapProvider::getFacadeClasses(InvocationStateViewFactory::class),
        );
    }

    private function functionReturn(string $function, ?string $viewName = null): ?Union
    {
        $source = $this->createStub(StatementsSource::class);
        $source->method('getFilePath')->willReturn('/app/example.php');
        $source->method('getFileName')->willReturn('example.php');

        $call = new FuncCall(new Name($function));
        $call->setAttribute('startFilePos', 0);
        $call->setAttribute('endFilePos', 10);
        $call->args = $viewName === null ? [] : [new Arg(new String_($viewName))];
        $event = new FunctionReturnTypeProviderEvent(
            $source,
            $function,
            $call,
            new Context(),
            new CodeLocation($source, $call),
        );

        return MissingViewHandler::getFunctionReturnType($event);
    }

    private function makeApplicationRoot(string $viewBinding, bool $bindTranslator, ?string $alias): string
    {
        $root = \sys_get_temp_dir() . '/psalm-laravel-invocation-' . \str_replace('.', '', \uniqid('', true));
        \mkdir($root . '/bootstrap/cache', 0777, true);
        \mkdir($root . '/config', 0777, true);
        $this->roots[] = $root;

        $translator = $bindTranslator
            ? "\$app->singleton('translator', static fn(): \\Illuminate\\Translation\\Translator => new \\Illuminate\\Translation\\Translator(new \\Illuminate\\Translation\\ArrayLoader(), 'en'));"
            : '';
        $applicationClass = $bindTranslator
            ? '\\Illuminate\\Foundation\\Application'
            : '\\' . InvocationStateNoTranslatorApplication::class;
        $aliasRegistration = $alias === null
            ? ''
            : "\\Illuminate\\Foundation\\AliasLoader::getInstance()->alias('{$alias}', \\Illuminate\\Support\\Facades\\View::class);";
        $viewSetup = "\$app->afterBootstrapping(\\Illuminate\\Foundation\\Bootstrap\\BootProviders::class, static function (\\Illuminate\\Foundation\\Application \$app): void { \$app->forgetInstance('view'); \$app->offsetUnset('view'); \$app->singleton('view', static fn(\\Illuminate\\Foundation\\Application \$app): {$viewBinding} => new {$viewBinding}(\$app['events'])); });";
        $bootstrap = "<?php\n"
            . "\$app = {$applicationClass}::configure(basePath: " . \var_export($root, true) . ")->create();\n"
            . $viewSetup . "\n"
            . $translator . "\n"
            . $aliasRegistration . "\n"
            . "return \$app;\n";
        \file_put_contents($root . '/bootstrap/app.php', $bootstrap);

        return $root;
    }

    private function removeDirectory(string $directory): void
    {
        if (!\is_dir($directory)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            $file->isDir() ? \rmdir($file->getPathname()) : \unlink($file->getPathname());
        }

        \rmdir($directory);
    }
}

final class InvocationStateViewFactory extends Factory
{
    public function __construct(Dispatcher $events)
    {
        $filesystem = new Filesystem();
        $engines = new EngineResolver();
        $engines->register('php', static fn(): PhpEngine => new PhpEngine($filesystem));

        parent::__construct($engines, new FileViewFinder($filesystem, []), $events);
    }
}

final class InvocationStateUnsupportedViewFactory
{
    public function __construct(Dispatcher $events) {}
}

final class InvocationStateNoTranslatorApplication extends \Illuminate\Foundation\Application
{
    public function bound($abstract): bool
    {
        return $abstract !== 'translator' && parent::bound($abstract);
    }
}
