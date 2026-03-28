<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Rules;

use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Name;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\LaravelPlugin\Handlers\Rules\NoEnvOutsideConfigHandler;
use Psalm\Plugin\EventHandler\Event\FunctionReturnTypeProviderEvent;
use Psalm\StatementsSource;

#[CoversClass(NoEnvOutsideConfigHandler::class)]
final class NoEnvOutsideConfigHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        NoEnvOutsideConfigHandler::init('/project/config');
    }

    #[Test]
    public function returns_env_function_id(): void
    {
        $this->assertSame(['env'], NoEnvOutsideConfigHandler::getFunctionIds());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function allowedFileProvider(): iterable
    {
        yield 'config file' => ['/project/config/app.php'];
        yield 'config subdirectory' => ['/project/config/services/api.php'];
        yield 'test file' => ['/project/tests/Unit/MyTest.php'];
        yield 'feature test' => ['/project/tests/Feature/MyTest.php'];
    }

    /**
     * Files inside config/ or tests/ should not trigger the issue.
     * If the handler incorrectly tried to emit an issue, it would throw
     * because no Psalm runtime is initialized in unit tests.
     */
    #[Test]
    #[DataProvider('allowedFileProvider')]
    public function skips_allowed_files(string $filePath): void
    {
        $event = $this->createEvent($filePath);

        $this->assertNotInstanceOf(\Psalm\Type\Union::class, NoEnvOutsideConfigHandler::getFunctionReturnType($event));
    }

    #[Test]
    public function trailing_separator_in_config_path_is_normalized(): void
    {
        NoEnvOutsideConfigHandler::init('/project/config/');

        $event = $this->createEvent('/project/config/app.php');

        $this->assertNotInstanceOf(\Psalm\Type\Union::class, NoEnvOutsideConfigHandler::getFunctionReturnType($event));
    }

    private function createEvent(string $filePath): FunctionReturnTypeProviderEvent
    {
        $source = $this->createStub(StatementsSource::class);
        $source->method('getFilePath')->willReturn($filePath);
        $source->method('getFileName')->willReturn(\basename($filePath));
        $source->method('getSuppressedIssues')->willReturn([]);

        $funcCall = new FuncCall(new Name('env'));
        $funcCall->setAttribute('startFilePos', 0);
        $funcCall->setAttribute('endFilePos', 10);

        return new FunctionReturnTypeProviderEvent(
            $source,
            'env',
            $funcCall,
            new Context(),
            new CodeLocation($source, $funcCall),
        );
    }
}
