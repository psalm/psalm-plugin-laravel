<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Translations;

use Illuminate\Translation\Translator;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\LaravelPlugin\Handlers\Translations\MissingTranslationHandler;
use Psalm\Plugin\EventHandler\Event\FunctionReturnTypeProviderEvent;
use Psalm\StatementsSource;
use Psalm\Type\Union;

#[CoversClass(MissingTranslationHandler::class)]
final class MissingTranslationHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        $translator = $this->createStub(Translator::class);
        $translator->method('has')->willReturnCallback(
            static fn(string $key): bool => \in_array($key, [
                'auth.failed',
                'auth.password',
                'auth.throttle',
                'messages.welcome',
            ], true),
        );

        MissingTranslationHandler::init($translator);
    }

    #[Test]
    public function returns_trans_and_double_underscore_function_ids(): void
    {
        $this->assertSame(['__', 'trans'], MissingTranslationHandler::getFunctionIds());
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function existingKeyProvider(): iterable
    {
        yield 'auth.failed' => ['auth.failed'];
        yield 'auth.password' => ['auth.password'];
        yield 'auth.throttle' => ['auth.throttle'];
        yield 'messages.welcome' => ['messages.welcome'];
    }

    /**
     * Translation keys that exist should not trigger the issue.
     * If the handler incorrectly tried to emit an issue, it would throw
     * because no Psalm runtime is initialized in unit tests.
     */
    #[Test]
    #[DataProvider('existingKeyProvider')]
    public function skips_existing_translation_keys(string $translationKey): void
    {
        $event = $this->createEvent($translationKey);

        $this->assertNotInstanceOf(Union::class, MissingTranslationHandler::getFunctionReturnType($event));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function skippedKeyProvider(): iterable
    {
        yield 'namespaced package key' => ['package::file.key'];
        yield 'another namespaced key' => ['notifications::email.greeting'];
    }

    /**
     * Namespaced keys should be skipped — not flagged even if the translation doesn't exist.
     */
    #[Test]
    #[DataProvider('skippedKeyProvider')]
    public function skips_namespaced_package_keys(string $translationKey): void
    {
        $event = $this->createEvent($translationKey);

        $this->assertNotInstanceOf(Union::class, MissingTranslationHandler::getFunctionReturnType($event));
    }

    #[Test]
    public function skips_empty_key(): void
    {
        $event = $this->createEvent('');

        $this->assertNotInstanceOf(Union::class, MissingTranslationHandler::getFunctionReturnType($event));
    }

    /**
     * When Translator::has() throws (e.g. malformed language file), the handler
     * should treat the key as existing to avoid false positives and crashes.
     */
    #[Test]
    public function treats_key_as_existing_when_translator_throws(): void
    {
        $translator = $this->createStub(Translator::class);
        $translator->method('has')->willThrowException(new \RuntimeException('Invalid language file'));

        MissingTranslationHandler::init($translator);

        $event = $this->createEvent('broken.key');

        $this->assertNotInstanceOf(Union::class, MissingTranslationHandler::getFunctionReturnType($event));

        // Re-init with working translator for other tests
        $this->setUp();
    }

    #[Test]
    public function skips_when_not_enabled(): void
    {
        $enabled = new \ReflectionProperty(MissingTranslationHandler::class, 'enabled');
        $enabled->setAccessible(true);
        $enabled->setValue(null, false);

        $event = $this->createEvent('nonexistent.key');

        $this->assertNotInstanceOf(Union::class, MissingTranslationHandler::getFunctionReturnType($event));

        // Re-enable for other tests
        $this->setUp();
    }

    #[Test]
    public function skips_no_arguments(): void
    {
        $source = $this->createStub(StatementsSource::class);
        $source->method('getFilePath')->willReturn('/app/Http/Controllers/TestController.php');
        $source->method('getFileName')->willReturn('TestController.php');

        $funcCall = new FuncCall(new Name('__'));
        $funcCall->setAttribute('startFilePos', 0);
        $funcCall->setAttribute('endFilePos', 10);
        $funcCall->args = [];

        $event = new FunctionReturnTypeProviderEvent(
            $source,
            '__',
            $funcCall,
            new Context(),
            new CodeLocation($source, $funcCall),
        );

        $this->assertNotInstanceOf(Union::class, MissingTranslationHandler::getFunctionReturnType($event));
    }

    #[Test]
    public function skips_dynamic_variable_argument(): void
    {
        $source = $this->createStub(StatementsSource::class);
        $source->method('getFilePath')->willReturn('/app/Http/Controllers/TestController.php');
        $source->method('getFileName')->willReturn('TestController.php');

        $funcCall = new FuncCall(new Name('__'));
        $funcCall->setAttribute('startFilePos', 0);
        $funcCall->setAttribute('endFilePos', 10);
        $funcCall->args = [new Arg(new Variable('key'))];

        $event = new FunctionReturnTypeProviderEvent(
            $source,
            '__',
            $funcCall,
            new Context(),
            new CodeLocation($source, $funcCall),
        );

        $this->assertNotInstanceOf(Union::class, MissingTranslationHandler::getFunctionReturnType($event));
    }

    private function createEvent(string $translationKey): FunctionReturnTypeProviderEvent
    {
        $source = $this->createStub(StatementsSource::class);
        $source->method('getFilePath')->willReturn('/app/Http/Controllers/TestController.php');
        $source->method('getFileName')->willReturn('TestController.php');
        $source->method('getSuppressedIssues')->willReturn([]);

        $funcCall = new FuncCall(new Name('__'));
        $funcCall->setAttribute('startFilePos', 0);
        $funcCall->setAttribute('endFilePos', 10);
        $funcCall->args = [new Arg(new String_($translationKey))];

        return new FunctionReturnTypeProviderEvent(
            $source,
            '__',
            $funcCall,
            new Context(),
            new CodeLocation($source, $funcCall),
        );
    }
}
