<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Magic;

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Providers\FoundationServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Magic\MacroDefinition;
use Psalm\LaravelPlugin\Handlers\Magic\MacroHandler;
use Psalm\Storage\MethodStorage;
use Psalm\Type;

/**
 * Verifies the {@see MacroHandler::FRAMEWORK_THROWS} map is applied when
 * synthesising pseudo-method storage.
 *
 * `MacroHandler::buildMethodStorage()` is private, so we drive it via reflection
 * rather than spinning up a full `AfterCodebasePopulated` lifecycle (which would
 * require booting Psalm's analyser, populating ClassLikeStorage, etc. for what is
 * a one-line lookup against a hardcoded map).
 *
 * The asserted shape (`throws[FQCN] => true`) matches what Psalm's own
 * `FunctionLikeDocblockScanner::analyzeStorageDocblock()` writes when parsing
 * `@throws` annotations on user code. Caller-side propagation is covered by
 * EmissionAppTest::an_unhandled_request_validate_reports_the_validation_exception.
 */
#[CoversClass(MacroHandler::class)]
final class MacroHandlerThrowsTest extends TestCase
{
    /** @var array<string, mixed> */
    private array $previousMacros = [];

    #[\Override]
    protected function setUp(): void
    {
        /** @var array<string, mixed> $macros */
        $macros = (new \ReflectionProperty(Request::class, 'macros'))->getValue();
        $this->previousMacros = $macros;
    }

    #[\Override]
    protected function tearDown(): void
    {
        (new \ReflectionProperty(Request::class, 'macros'))->setValue(null, $this->previousMacros);
    }

    #[Test]
    public function validate_macro_records_validation_exception_in_throws(): void
    {
        $this->registerFrameworkValidateMacros();
        $storage = $this->buildStorage(Request::class, 'validate');

        $this->assertSame([ValidationException::class => true], $storage->throws);
    }

    #[Test]
    public function a_replacement_validate_macro_records_no_throws(): void
    {
        // An app or package replacing Laravel's macro has no ValidationException guarantee.
        $this->registerFrameworkValidateMacros();
        Request::macro('validate', static fn(array $rules): array => []);

        $this->assertSame([], $this->buildStorage(Request::class, 'validate')->throws);
    }

    #[Test]
    public function another_framework_macro_closure_registered_as_validate_records_no_throws(): void
    {
        // The other FoundationServiceProvider closures live in the same file as the validation ones.
        $provider = new FoundationServiceProvider(new Application());
        $provider->registerRequestSignatureValidation();

        $macros = (new \ReflectionProperty(Request::class, 'macros'))->getValue();
        $this->assertInstanceOf(\Closure::class, $macros['hasValidRelativeSignature']);
        Request::macro('validate', $macros['hasValidRelativeSignature']);

        $this->assertSame([], $this->buildStorage(Request::class, 'validate')->throws);
    }

    #[Test]
    public function validate_macro_on_non_request_class_records_no_throws(): void
    {
        // Pins the class-scoping invariant: a user (or third-party package)
        // registering `Stringable::macro('validate', ...)` must not inherit
        // the `Request::validate` throw set just because the names collide.
        $storage = $this->buildStorage(\Illuminate\Support\Stringable::class, 'validate');

        $this->assertSame([], $storage->throws);
    }

    private function registerFrameworkValidateMacros(): void
    {
        $provider = new FoundationServiceProvider(new Application());
        (new \ReflectionMethod($provider, 'registerRequestValidation'))->invoke($provider);
    }

    /**
     * @param class-string $declaringClass
     * @param lowercase-string $methodName
     */
    private function buildStorage(string $declaringClass, string $methodName): MethodStorage
    {
        $def = new MacroDefinition(
            declaringClass: $declaringClass,
            methodName: $methodName,
            casedName: $methodName,
            params: [],
            returnType: Type::getMixed(),
            signatureReturnType: null,
        );

        $reflection = new \ReflectionMethod(MacroHandler::class, 'buildMethodStorage');

        $result = $reflection->invoke(null, $def, false);
        $this->assertInstanceOf(MethodStorage::class, $result);

        return $result;
    }
}
