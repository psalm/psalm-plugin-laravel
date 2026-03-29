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
    /** @var array<string, string|array<string, string>> Translation values used by the stub translator */
    private const TRANSLATIONS = [
        'auth.failed' => 'These credentials do not match our records.',
        'auth.password' => 'The provided password is incorrect.',
        'auth.throttle' => 'Too many login attempts.',
        'messages.welcome' => 'Welcome!',
        'validation.accepted' => ['rule' => 'The :attribute must be accepted.', 'values' => ':values'],
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->initWorkingTranslator();
    }

    /**
     * Restore the handler to a known-good state with a working translator.
     * Called from setUp() and also used by tests that swap in a broken
     * translator — tearDown() ensures the next test always starts clean.
     */
    protected function tearDown(): void
    {
        $this->initWorkingTranslator();

        parent::tearDown();
    }

    private function initWorkingTranslator(): void
    {
        $translator = $this->createStub(Translator::class);
        $translator->method('has')->willReturnCallback(
            static fn(string $key): bool => \array_key_exists($key, self::TRANSLATIONS),
        );
        $translator->method('get')->willReturnCallback(
            static fn(string $key): string|array => self::TRANSLATIONS[$key] ?? $key,
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
    public static function existingStringKeyProvider(): iterable
    {
        yield 'auth.failed' => ['auth.failed'];
        yield 'auth.password' => ['auth.password'];
        yield 'auth.throttle' => ['auth.throttle'];
        yield 'messages.welcome' => ['messages.welcome'];
    }

    /**
     * Translation keys that resolve to a string should return string type.
     */
    #[Test]
    #[DataProvider('existingStringKeyProvider')]
    public function returns_string_type_for_existing_string_translation(string $translationKey): void
    {
        $event = $this->createEvent($translationKey);
        $result = MissingTranslationHandler::getFunctionReturnType($event);

        $this->assertInstanceOf(Union::class, $result);
        $this->assertTrue($result->isString(), "Expected string type for key '{$translationKey}'");
    }

    /**
     * Translation keys that resolve to an array should return array type.
     */
    #[Test]
    public function returns_array_type_for_existing_array_translation(): void
    {
        $event = $this->createEvent('validation.accepted');
        $result = MissingTranslationHandler::getFunctionReturnType($event);

        $this->assertInstanceOf(Union::class, $result);
        $this->assertTrue($result->hasArray(), "Expected array type for key 'validation.accepted'");
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
     * @return iterable<string, array{\Throwable}>
     */
    public static function throwableProvider(): iterable
    {
        yield 'RuntimeException' => [new \RuntimeException('Invalid language file')];
        yield 'ParseError from malformed PHP' => [new \ParseError('syntax error')];
    }

    /**
     * When Translator::has() throws (e.g. malformed language file), the handler
     * should return string|array to avoid emitting a false MissingTranslation
     * for a key that may actually exist.
     *
     * Tests both \Exception and \Error subclasses — PHP lang files with syntax
     * errors throw \ParseError (\Error), while invalid JSON throws \RuntimeException.
     */
    #[Test]
    #[DataProvider('throwableProvider')]
    public function returns_string_or_array_when_has_throws(\Throwable $exception): void
    {
        $translator = $this->createStub(Translator::class);
        $translator->method('has')->willThrowException($exception);

        MissingTranslationHandler::init($translator);

        $event = $this->createEvent('broken.key');
        $result = MissingTranslationHandler::getFunctionReturnType($event);

        $this->assertInstanceOf(Union::class, $result);
        $this->assertTrue($result->hasString(), 'Expected string in union type');
        $this->assertTrue($result->hasArray(), 'Expected array in union type');
    }

    /**
     * When Translator::has() succeeds but get() throws, the handler should
     * return string|array as a safe fallback since we know the key exists.
     */
    #[Test]
    #[DataProvider('throwableProvider')]
    public function returns_string_or_array_when_get_throws(\Throwable $exception): void
    {
        $translator = $this->createStub(Translator::class);
        $translator->method('has')->willReturn(true);
        $translator->method('get')->willThrowException($exception);

        MissingTranslationHandler::init($translator);

        $event = $this->createEvent('broken.value');
        $result = MissingTranslationHandler::getFunctionReturnType($event);

        $this->assertInstanceOf(Union::class, $result);
        $this->assertTrue($result->hasString(), 'Expected string in union type');
        $this->assertTrue($result->hasArray(), 'Expected array in union type');
    }

    #[Test]
    public function skips_when_not_enabled(): void
    {
        $enabled = new \ReflectionProperty(MissingTranslationHandler::class, 'enabled');
        $enabled->setAccessible(true);
        $enabled->setValue(null, false);

        $event = $this->createEvent('nonexistent.key');

        $this->assertNotInstanceOf(Union::class, MissingTranslationHandler::getFunctionReturnType($event));
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
