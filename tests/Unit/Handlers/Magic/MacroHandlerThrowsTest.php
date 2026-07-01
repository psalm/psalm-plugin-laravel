<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Magic;

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
 * `@throws` annotations on user code. Caller-side propagation is a Psalm-side
 * concern (it doesn't currently run for pseudo-methods); this test pins the
 * plugin contract regardless of that gap.
 */
#[CoversClass(MacroHandler::class)]
final class MacroHandlerThrowsTest extends TestCase
{
    #[Test]
    public function validate_macro_records_validation_exception_in_throws(): void
    {
        $storage = $this->buildStorage(\Illuminate\Http\Request::class, 'validate');

        $this->assertSame([ValidationException::class => true], $storage->throws);
    }

    #[Test]
    public function validate_with_bag_macro_records_validation_exception_in_throws(): void
    {
        // Macro names are stored lowercased — `MacroDefinition::$methodName` is
        // typed `lowercase-string` and `MacroRegistry::init()` lowercases the key
        // before storing. The throws map must match that casing.
        $storage = $this->buildStorage(\Illuminate\Http\Request::class, 'validatewithbag');

        $this->assertSame([ValidationException::class => true], $storage->throws);
    }

    #[Test]
    public function unmapped_macro_records_no_throws(): void
    {
        $storage = $this->buildStorage(\Illuminate\Http\Request::class, 'whatever');

        $this->assertSame([], $storage->throws);
    }

    #[Test]
    public function validate_macro_on_non_request_class_records_no_throws(): void
    {
        // Pins the class-scoping invariant: a user (or third-party package)
        // registering `Stringable::macro('validate', ...)` must not inherit
        // the `Request::validate` throw set just because the names collide.
        // The propagation gap masks this today, but the docblock on
        // `MacroHandler::FRAMEWORK_THROWS` commits to the scope being correct
        // for when Psalm closes that gap.
        $storage = $this->buildStorage(\Illuminate\Support\Stringable::class, 'validate');

        $this->assertSame([], $storage->throws);
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
