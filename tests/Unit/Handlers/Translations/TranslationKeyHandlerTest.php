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
use Psalm\LaravelPlugin\Handlers\Translations\TranslationKeyHandler;
use Psalm\Plugin\EventHandler\Event\FunctionReturnTypeProviderEvent;
use Psalm\StatementsSource;
use Psalm\Type;
use Psalm\Type\Union;

#[CoversClass(TranslationKeyHandler::class)]
final class TranslationKeyHandlerTest extends TestCase
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
        $translator = $this->createStub(Translator::class);
        $translator->method('has')->willReturnCallback(
            static fn(string $key): bool => \array_key_exists($key, self::TRANSLATIONS),
        );
        $translator->method('get')->willReturnCallback(
            static fn(string $key): string|array => self::TRANSLATIONS[$key] ?? $key,
        );

        TranslationKeyHandler::init($translator, reportMissing: true);
    }

    #[Test]
    public function returns_trans_and_double_underscore_function_ids(): void
    {
        $this->assertSame(['__', 'trans'], TranslationKeyHandler::getFunctionIds());
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
        $result = TranslationKeyHandler::getFunctionReturnType($event);

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
        $result = TranslationKeyHandler::getFunctionReturnType($event);

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

        $this->assertNotInstanceOf(Union::class, TranslationKeyHandler::getFunctionReturnType($event));
    }

    #[Test]
    public function skips_empty_key(): void
    {
        $event = $this->createEvent('');

        $this->assertNotInstanceOf(Union::class, TranslationKeyHandler::getFunctionReturnType($event));
    }

    /**
     * When Translator::has() throws (e.g. malformed language file), the handler
     * should return string|array to avoid emitting a false MissingTranslation
     * for a key that may actually exist.
     */
    #[Test]
    public function returns_string_or_array_when_has_throws(): void
    {
        $translator = $this->createStub(Translator::class);
        $translator->method('has')->willThrowException(new \RuntimeException('Invalid language file'));

        TranslationKeyHandler::init($translator, reportMissing: true);

        $event = $this->createEvent('broken.key');
        $result = TranslationKeyHandler::getFunctionReturnType($event);

        $this->assertInstanceOf(Union::class, $result);
        $this->assertTrue($result->hasString(), 'Expected string in union type');
        $this->assertTrue($result->hasArray(), 'Expected array in union type');

        // Re-init with working translator for other tests
        $this->setUp();
    }

    /**
     * When Translator::has() succeeds but get() throws, the handler should
     * return string|array as a safe fallback since we know the key exists.
     */
    #[Test]
    public function returns_string_or_array_when_get_throws(): void
    {
        $translator = $this->createStub(Translator::class);
        $translator->method('has')->willReturn(true);
        $translator->method('get')->willThrowException(new \RuntimeException('Cannot read value'));

        TranslationKeyHandler::init($translator, reportMissing: true);

        $event = $this->createEvent('broken.value');
        $result = TranslationKeyHandler::getFunctionReturnType($event);

        $this->assertInstanceOf(Union::class, $result);
        $this->assertTrue($result->hasString(), 'Expected string in union type');
        $this->assertTrue($result->hasArray(), 'Expected array in union type');

        // Re-init with working translator for other tests
        $this->setUp();
    }

    #[Test]
    public function returns_null_when_translator_not_initialized(): void
    {
        $translator = new \ReflectionProperty(TranslationKeyHandler::class, 'translator');
        $translator->setAccessible(true);
        $translator->setValue(null, null);

        $event = $this->createEvent('auth.failed');

        $this->assertNull(TranslationKeyHandler::getFunctionReturnType($event));

        // Re-init for other tests
        $this->setUp();
    }

    /**
     * When reportMissing is false, existing keys should still return precise types —
     * the config only controls issue emission, not type narrowing.
     */
    #[Test]
    public function resolves_precise_type_when_report_missing_is_false(): void
    {
        $translator = $this->createStub(Translator::class);
        $translator->method('has')->willReturnCallback(
            static fn(string $key): bool => \array_key_exists($key, self::TRANSLATIONS),
        );
        $translator->method('get')->willReturnCallback(
            static fn(string $key): string|array => self::TRANSLATIONS[$key] ?? $key,
        );

        TranslationKeyHandler::init($translator, reportMissing: false);

        $event = $this->createEvent('auth.failed');
        $result = TranslationKeyHandler::getFunctionReturnType($event);

        $this->assertInstanceOf(Union::class, $result);
        $this->assertTrue($result->isString(), "Expected string type for existing key even with reportMissing=false");

        // Re-init for other tests
        $this->setUp();
    }

    /**
     * When reportMissing is false, missing keys should return null (falling through
     * to TransHandler) without emitting a MissingTranslation issue.
     */
    #[Test]
    public function returns_null_for_missing_key_when_report_missing_is_false(): void
    {
        $translator = $this->createStub(Translator::class);
        $translator->method('has')->willReturn(false);

        TranslationKeyHandler::init($translator, reportMissing: false);

        $event = $this->createEvent('nonexistent.key');

        $this->assertNull(TranslationKeyHandler::getFunctionReturnType($event));

        // Re-init for other tests
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

        $this->assertNotInstanceOf(Union::class, TranslationKeyHandler::getFunctionReturnType($event));
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

        $this->assertNotInstanceOf(Union::class, TranslationKeyHandler::getFunctionReturnType($event));
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
