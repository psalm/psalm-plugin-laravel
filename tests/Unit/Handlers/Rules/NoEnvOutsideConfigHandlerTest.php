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
use Psalm\Type\Union;

#[CoversClass(NoEnvOutsideConfigHandler::class)]
final class NoEnvOutsideConfigHandlerTest extends TestCase
{
    #[Test]
    public function returns_env_function_id(): void
    {
        $this->assertSame(['env'], NoEnvOutsideConfigHandler::getFunctionIds());
    }

    /**
     * Paths are built from DIRECTORY_SEPARATOR to stay consistent with the handler,
     * which uses DIRECTORY_SEPARATOR for its segment match. Psalm normalizes file
     * paths to the host separator before dispatching to plugins.
     *
     * @return iterable<string, array{string}>
     */
    public static function allowedFileProvider(): iterable
    {
        $s = \DIRECTORY_SEPARATOR;

        yield 'app config file' => ["{$s}project{$s}config{$s}app.php"];
        yield 'app config subdirectory' => ["{$s}project{$s}config{$s}services{$s}api.php"];
        yield 'package config' => ["{$s}home{$s}dev{$s}spatie{$s}laravel-backup{$s}config{$s}backup.php"];
        yield 'monorepo sub-package config' => ["{$s}monorepo{$s}packages{$s}forms{$s}config{$s}forms.php"];
        yield 'vendor package config' => ["{$s}project{$s}vendor{$s}spatie{$s}laravel-backup{$s}config{$s}backup.php"];
        yield 'test file' => ["{$s}project{$s}tests{$s}Unit{$s}MyTest.php"];
        yield 'feature test' => ["{$s}project{$s}tests{$s}Feature{$s}MyTest.php"];
    }

    /**
     * Files inside any config/ segment or tests/ directory should not trigger the issue.
     * If the handler incorrectly tried to emit an issue, it would throw because no Psalm
     * runtime is initialized in unit tests.
     */
    #[Test]
    #[DataProvider('allowedFileProvider')]
    public function skips_allowed_files(string $filePath): void
    {
        $event = $this->createEvent($filePath);

        $this->assertNotInstanceOf(Union::class, NoEnvOutsideConfigHandler::getFunctionReturnType($event));
    }

    /**
     * Sanity check for the structural matcher: paths without a literal `/config/`
     * segment (including substring look-alikes like `configuration/` and `.config/`)
     * should be treated as outside the config directory.
     *
     * Reaching the emit branch calls IssueBuffer::accepts(), which delegates to
     * Config::getInstance() and throws UnexpectedValueException('No config initialized')
     * when Psalm isn't bootstrapped. We assert that specific exception to confirm the
     * handler actually reached issue emission, rather than throwing somewhere earlier.
     *
     * @return iterable<string, array{string}>
     */
    public static function rejectedFileProvider(): iterable
    {
        $s = \DIRECTORY_SEPARATOR;

        yield 'application code' => ["{$s}project{$s}app{$s}Services{$s}PaymentService.php"];
        yield 'substring-not-segment (prefix)' => ["{$s}project{$s}app{$s}configuration{$s}Foo.php"];
        yield 'substring-not-segment (suffix)' => ["{$s}project{$s}app{$s}myconfig{$s}Foo.php"];
        yield 'hidden config dir' => ["{$s}project{$s}.config{$s}foo.php"];
    }

    #[Test]
    #[DataProvider('rejectedFileProvider')]
    public function rejects_non_config_files(string $filePath): void
    {
        $event = $this->createEvent($filePath);

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessageMatches('/config.*initializ|initializ.*config/i');

        NoEnvOutsideConfigHandler::getFunctionReturnType($event);
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
