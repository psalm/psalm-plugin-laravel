<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Views;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\LaravelPlugin\Handlers\Views\MissingViewHandler;
use Psalm\Plugin\EventHandler\Event\FunctionReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\StatementsSource;

#[CoversClass(MissingViewHandler::class)]
final class MissingViewHandlerTest extends TestCase
{
    private static string $fixtureDir;

    public static function setUpBeforeClass(): void
    {
        self::$fixtureDir = \sys_get_temp_dir() . '/psalm-laravel-test-views-' . \getmypid();
        \mkdir(self::$fixtureDir . '/emails', 0777, true);
        \file_put_contents(self::$fixtureDir . '/welcome.blade.php', '');
        \file_put_contents(self::$fixtureDir . '/emails/invite.blade.php', '');
        \file_put_contents(self::$fixtureDir . '/legacy.php', '');
    }

    public static function tearDownAfterClass(): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator(self::$fixtureDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                \rmdir($file->getPathname());
            } else {
                \unlink($file->getPathname());
            }
        }

        \rmdir(self::$fixtureDir);
    }

    protected function setUp(): void
    {
        MissingViewHandler::init([self::$fixtureDir]);
    }

    #[Test]
    public function returns_view_function_id(): void
    {
        $this->assertSame(['view'], MissingViewHandler::getFunctionIds());
    }

    #[Test]
    public function returns_factory_class_name(): void
    {
        $this->assertSame(
            [\Illuminate\View\Factory::class],
            MissingViewHandler::getClassLikeNames(),
        );
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function existingViewProvider(): iterable
    {
        yield 'blade template in root' => ['welcome'];
        yield 'blade template in subdirectory' => ['emails.invite'];
        yield 'php template' => ['legacy'];
    }

    /**
     * Views that exist on disk should not trigger the issue.
     * If the handler incorrectly tried to emit an issue, it would throw
     * because no Psalm runtime is initialized in unit tests.
     */
    #[Test]
    #[DataProvider('existingViewProvider')]
    public function skips_existing_views(string $viewName): void
    {
        $event = $this->createFunctionEvent($viewName);

        $this->assertNull(MissingViewHandler::getFunctionReturnType($event));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function skippedViewProvider(): iterable
    {
        yield 'namespaced view' => ['mail::html.header'];
        yield 'another namespaced view' => ['notifications::email'];
    }

    /**
     * Namespaced views should be skipped — not flagged even if the file doesn't exist.
     */
    #[Test]
    #[DataProvider('skippedViewProvider')]
    public function skips_namespaced_views(string $viewName): void
    {
        $event = $this->createFunctionEvent($viewName);

        $this->assertNull(MissingViewHandler::getFunctionReturnType($event));
    }

    #[Test]
    public function normalizes_trailing_separator_in_view_paths(): void
    {
        MissingViewHandler::init([self::$fixtureDir . '/']);

        $event = $this->createFunctionEvent('welcome');

        $this->assertNull(MissingViewHandler::getFunctionReturnType($event));
    }

    #[Test]
    public function skips_when_not_enabled(): void
    {
        $enabled = new \ReflectionProperty(MissingViewHandler::class, 'enabled');
        $enabled->setValue(null, false);

        $event = $this->createFunctionEvent('nonexistent');

        $this->assertNull(MissingViewHandler::getFunctionReturnType($event));

        // Re-enable for other tests
        MissingViewHandler::init([self::$fixtureDir]);
    }

    #[Test]
    public function skips_no_arguments(): void
    {
        $source = $this->createStub(StatementsSource::class);
        $source->method('getFilePath')->willReturn('/app/Http/Controllers/TestController.php');
        $source->method('getFileName')->willReturn('TestController.php');

        $funcCall = new FuncCall(new Name('view'));
        $funcCall->setAttribute('startFilePos', 0);
        $funcCall->setAttribute('endFilePos', 10);
        $funcCall->args = [];

        $event = new FunctionReturnTypeProviderEvent(
            $source,
            'view',
            $funcCall,
            new Context(),
            new CodeLocation($source, $funcCall),
        );

        $this->assertNull(MissingViewHandler::getFunctionReturnType($event));
    }

    #[Test]
    public function skips_dynamic_variable_argument(): void
    {
        $source = $this->createStub(StatementsSource::class);
        $source->method('getFilePath')->willReturn('/app/Http/Controllers/TestController.php');
        $source->method('getFileName')->willReturn('TestController.php');

        $funcCall = new FuncCall(new Name('view'));
        $funcCall->setAttribute('startFilePos', 0);
        $funcCall->setAttribute('endFilePos', 10);
        $funcCall->args = [new Arg(new Variable('viewName'))];

        $event = new FunctionReturnTypeProviderEvent(
            $source,
            'view',
            $funcCall,
            new Context(),
            new CodeLocation($source, $funcCall),
        );

        $this->assertNull(MissingViewHandler::getFunctionReturnType($event));
    }

    // --- View::make() (MethodReturnTypeProvider) tests ---

    #[Test]
    #[DataProvider('existingViewProvider')]
    public function method_skips_existing_views(string $viewName): void
    {
        $event = $this->createMethodEvent('make', $viewName);

        $this->assertNull(MissingViewHandler::getMethodReturnType($event));
    }

    #[Test]
    #[DataProvider('skippedViewProvider')]
    public function method_skips_namespaced_views(string $viewName): void
    {
        $event = $this->createMethodEvent('make', $viewName);

        $this->assertNull(MissingViewHandler::getMethodReturnType($event));
    }

    #[Test]
    public function method_skips_non_make_methods(): void
    {
        $event = $this->createMethodEvent('exists', 'nonexistent');

        $this->assertNull(MissingViewHandler::getMethodReturnType($event));
    }

    private function createFunctionEvent(string $viewName): FunctionReturnTypeProviderEvent
    {
        $source = $this->createStub(StatementsSource::class);
        $source->method('getFilePath')->willReturn('/app/Http/Controllers/TestController.php');
        $source->method('getFileName')->willReturn('TestController.php');
        $source->method('getSuppressedIssues')->willReturn([]);

        $funcCall = new FuncCall(new Name('view'));
        $funcCall->setAttribute('startFilePos', 0);
        $funcCall->setAttribute('endFilePos', 10);
        $funcCall->args = [new Arg(new String_($viewName))];

        return new FunctionReturnTypeProviderEvent(
            $source,
            'view',
            $funcCall,
            new Context(),
            new CodeLocation($source, $funcCall),
        );
    }

    private function createMethodEvent(string $methodName, string $viewName): MethodReturnTypeProviderEvent
    {
        $source = $this->createStub(StatementsSource::class);
        $source->method('getFilePath')->willReturn('/app/Http/Controllers/TestController.php');
        $source->method('getFileName')->willReturn('TestController.php');
        $source->method('getSuppressedIssues')->willReturn([]);

        $methodCall = new MethodCall(
            new Variable('factory'),
            $methodName,
        );
        $methodCall->setAttribute('startFilePos', 0);
        $methodCall->setAttribute('endFilePos', 10);
        $methodCall->args = [new Arg(new String_($viewName))];

        return new MethodReturnTypeProviderEvent(
            $source,
            \Illuminate\View\Factory::class,
            $methodName,
            $methodCall,
            new Context(),
            new CodeLocation($source, $methodCall),
        );
    }
}
