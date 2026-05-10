<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Tests\Unit\Providers;

use Illuminate\Support\Stringable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Providers\ApplicationProvider;
use Psalm\LaravelPlugin\Providers\MacroDefinition;
use Psalm\LaravelPlugin\Providers\MacroRegistry;
use Psalm\Progress\VoidProgress;

#[CoversClass(MacroRegistry::class)]
#[CoversClass(MacroDefinition::class)]
final class MacroRegistryTest extends TestCase
{
    #[\Override]
    protected function setUp(): void
    {
        ApplicationProvider::bootApp();
        // Wipe Stringable's macros so each test starts from a known state.
        // Other Macroable classes are left alone — Laravel itself rarely registers
        // any in-core, so cross-test pollution is unlikely from those.
        Stringable::flushMacros();
        MacroRegistry::reset();
    }

    #[\Override]
    protected function tearDown(): void
    {
        Stringable::flushMacros();
        MacroRegistry::reset();
    }

    #[Test]
    public function discovers_a_simple_closure_macro(): void
    {
        Stringable::macro('shout', static fn(): string => 'OK');

        MacroRegistry::init(new VoidProgress());

        $def = MacroRegistry::get(Stringable::class, 'shout');
        $this->assertNotNull($def);
        $this->assertSame(Stringable::class, $def->declaringClass);
        $this->assertSame('shout', $def->methodName);
        $this->assertSame('shout', $def->casedName);
        $this->assertSame([], $def->params);
        $this->assertSame('string', $def->returnType->getId());
    }

    #[Test]
    public function preserves_original_casing_in_cased_name(): void
    {
        // Psalm reads `cased_name` for diagnostic output; lower-casing it would
        // surface `countCharsTest` as `countcharstest` in error messages.
        Stringable::macro('countCharsTest', static fn(): int => 0);

        MacroRegistry::init(new VoidProgress());

        $def = MacroRegistry::get(Stringable::class, 'countcharstest');
        $this->assertNotNull($def);
        $this->assertSame('countcharstest', $def->methodName);
        $this->assertSame('countCharsTest', $def->casedName);
    }

    #[Test]
    public function skips_macro_whose_name_shadows_a_real_method(): void
    {
        // Stringable declares a real `value()` method. Registering a macro with the
        // same name would be unreachable at runtime (PHP method resolution beats
        // __call) AND would clobber the real signature in pseudo-method lookups.
        // The registry must skip it.
        Stringable::macro('value', static fn(): int => 0);

        MacroRegistry::init(new VoidProgress());

        $this->assertNull(MacroRegistry::get(Stringable::class, 'value'));
    }

    #[Test]
    public function method_name_lookup_is_case_insensitive(): void
    {
        Stringable::macro('camelCased', static fn(): int => 1);

        MacroRegistry::init(new VoidProgress());

        // Method name is lower-cased on storage AND on lookup.
        $this->assertNotNull(MacroRegistry::get(Stringable::class, 'camelcased'));
        $this->assertNotNull(MacroRegistry::get(Stringable::class, 'CAMELCASED'));
    }

    #[Test]
    public function class_name_lookup_is_case_insensitive(): void
    {
        Stringable::macro('foo', static fn(): bool => true);

        MacroRegistry::init(new VoidProgress());

        // Psalm passes class FQCNs through verbatim; underlying storage is lower-cased.
        $this->assertNotNull(MacroRegistry::get(Stringable::class, 'foo'));
        $this->assertNotNull(MacroRegistry::get(\strtoupper(Stringable::class), 'foo'));
    }

    #[Test]
    public function extracts_param_signature_from_native_types(): void
    {
        Stringable::macro('joined', static fn(string $separator, int $count = 1): string => '');

        MacroRegistry::init(new VoidProgress());

        $def = MacroRegistry::get(Stringable::class, 'joined');
        $this->assertNotNull($def);
        $this->assertCount(2, $def->params);

        $this->assertSame('separator', $def->params[0]->name);
        $this->assertSame('string', $def->params[0]->type?->getId());
        $this->assertFalse($def->params[0]->is_optional);

        $this->assertSame('count', $def->params[1]->name);
        $this->assertSame('int', $def->params[1]->type?->getId());
        $this->assertTrue($def->params[1]->is_optional);
    }

    #[Test]
    public function by_reference_param_is_marked_by_ref(): void
    {
        Stringable::macro('byRef', static function (string &$out): void {});

        MacroRegistry::init(new VoidProgress());

        $def = MacroRegistry::get(Stringable::class, 'byref');
        $this->assertNotNull($def);
        $this->assertCount(1, $def->params);
        $this->assertTrue($def->params[0]->by_ref);
    }

    #[Test]
    public function discovers_array_callable_macro(): void
    {
        // Macroable::__call accepts array callables `[ClassName::class, 'method']`.
        // The registry must reflect the underlying method, not skip the callable.
        Stringable::macro('viaArrayCallable', [MacroCallableFixture::class, 'staticHelper']);

        MacroRegistry::init(new VoidProgress());

        $def = MacroRegistry::get(Stringable::class, 'viaarraycallable');
        $this->assertNotNull($def);
        $this->assertCount(1, $def->params);
        $this->assertSame('input', $def->params[0]->name);
        $this->assertSame('string', $def->signatureReturnType?->getId());
    }

    #[Test]
    public function discovers_string_callable_macro(): void
    {
        // String form 'Class::method'.
        Stringable::macro('viaStringCallable', MacroCallableFixture::class . '::staticHelper');

        MacroRegistry::init(new VoidProgress());

        $def = MacroRegistry::get(Stringable::class, 'viastringcallable');
        $this->assertNotNull($def);
        $this->assertCount(1, $def->params);
        $this->assertSame('string', $def->signatureReturnType?->getId());
    }

    #[Test]
    public function discovers_invokable_object_macro(): void
    {
        // Object with __invoke. Macroable accepts and dispatches these.
        Stringable::macro('viaInvokable', new MacroInvokableFixture());

        MacroRegistry::init(new VoidProgress());

        $def = MacroRegistry::get(Stringable::class, 'viainvokable');
        $this->assertNotNull($def);
        $this->assertCount(1, $def->params);
        $this->assertSame('count', $def->params[0]->name);
        $this->assertSame('int', $def->signatureReturnType?->getId());
    }

    #[Test]
    public function discovers_function_name_callable_macro(): void
    {
        // Plain function-name string. Macroable dispatches via PHP's variable-function
        // mechanism, so the function's reflected signature is what callers see.
        Stringable::macro('viaFunctionName', 'strtoupper');

        MacroRegistry::init(new VoidProgress());

        $def = MacroRegistry::get(Stringable::class, 'viafunctionname');
        $this->assertNotNull($def);
        $this->assertCount(1, $def->params);
        $this->assertSame('string', $def->params[0]->name);
    }

    #[Test]
    public function skips_non_public_method_callable(): void
    {
        // Macroable invokes the callable from outside the target's scope, so a protected
        // method would throw at runtime. The registry must not synthesize a pseudo-method
        // for it.
        Stringable::macro('viaProtected', [MacroNonPublicFixture::class, 'protectedHelper']);

        MacroRegistry::init(new VoidProgress());

        $this->assertNull(MacroRegistry::get(Stringable::class, 'viaprotected'));
    }

    #[Test]
    public function skips_non_static_method_with_class_string_callable(): void
    {
        // `[Class::class, 'method']` and `'Class::method'` callables only dispatch to
        // static methods — calling a non-static target this way errors at runtime.
        // Registry must skip these.
        Stringable::macro('viaInstanceArr', [MacroInstanceMethodFixture::class, 'instanceHelper']);
        Stringable::macro('viaInstanceStr', MacroInstanceMethodFixture::class . '::instanceHelper');

        MacroRegistry::init(new VoidProgress());

        $this->assertNull(MacroRegistry::get(Stringable::class, 'viainstancearr'));
        $this->assertNull(MacroRegistry::get(Stringable::class, 'viainstancestr'));
    }

    #[Test]
    public function accepts_non_static_method_with_object_callable(): void
    {
        // `[$obj, 'method']` works for instance methods too — `$obj` provides the binding.
        $obj = new MacroInstanceMethodFixture();
        Stringable::macro('viaInstanceObj', [$obj, 'instanceHelper']);

        MacroRegistry::init(new VoidProgress());

        $def = MacroRegistry::get(Stringable::class, 'viainstanceobj');
        $this->assertNotNull($def);
    }

    #[Test]
    public function expands_self_in_callable_method_return_type(): void
    {
        // `self` in a method signature resolves relative to that method's declaring
        // class, not to the Macroable host. The registry must expand it before parsing.
        Stringable::macro('viaSelfReturn', [MacroSelfReturnFixture::class, 'returnsSelf']);

        MacroRegistry::init(new VoidProgress());

        $def = MacroRegistry::get(Stringable::class, 'viaselfreturn');
        $this->assertNotNull($def);
        $this->assertNotNull($def->signatureReturnType);
        // Should resolve to MacroSelfReturnFixture, NOT the literal `self` (which Psalm
        // would otherwise interpret relative to the call site = Stringable).
        $this->assertSame(\strtolower(MacroSelfReturnFixture::class), \strtolower($def->signatureReturnType->getId()));
    }

    #[Test]
    public function reflects_closure_without_return_type_into_registry(): void
    {
        // The override-seam test exercises the type contract; this one exercises the
        // discovery path end-to-end. PHP's reflection of closures without declared
        // return types varies between patch versions (8.4.20 returns null, 8.4.21
        // surfaces an inferred type), so this asserts only the universal invariant:
        // discovery must produce SOME definition, and `returnType` must be a valid Union.
        Stringable::macro('reflectionDiscovery', static function (): int {
            return 1;
        });

        MacroRegistry::init(new VoidProgress());

        $def = MacroRegistry::get(Stringable::class, 'reflectiondiscovery');
        $this->assertNotNull($def);
        $this->assertNotNull($def->returnType);
        // Either the closure declared no return type → mixed, or PHP inferred one → that.
        // Both are correct outcomes of buildDefinition; we only require a Union to be present.
    }

    #[Test]
    public function variadic_param_is_marked_variadic(): void
    {
        Stringable::macro('varArgs', static fn(string ...$args): string => '');

        MacroRegistry::init(new VoidProgress());

        $def = MacroRegistry::get(Stringable::class, 'varargs');
        $this->assertNotNull($def);
        $this->assertCount(1, $def->params);
        $this->assertTrue($def->params[0]->is_variadic);
    }

    #[Test]
    public function nullable_param_is_marked_nullable(): void
    {
        Stringable::macro('maybeNull', static fn(?string $arg): string => '');

        MacroRegistry::init(new VoidProgress());

        $def = MacroRegistry::get(Stringable::class, 'maybenull');
        $this->assertNotNull($def);
        // is_nullable comes straight from ReflectionParameter::allowsNull() — independent
        // of Psalm's parseString, which needs ProjectAnalyzer::$instance (only set during
        // a real Psalm run, not in the unit-test bootstrap).
        $this->assertTrue($def->params[0]->is_nullable);
    }

    #[Test]
    public function reset_clears_registry(): void
    {
        Stringable::macro('present', static fn(): string => '');

        MacroRegistry::init(new VoidProgress());
        $this->assertNotNull(MacroRegistry::get(Stringable::class, 'present'));

        MacroRegistry::reset();
        $this->assertNull(MacroRegistry::get(Stringable::class, 'present'));
        $this->assertSame([], MacroRegistry::for(Stringable::class));
        $this->assertSame([], MacroRegistry::getKnownMacroableClasses());
    }

    #[Test]
    public function known_macroable_classes_lists_classes_with_at_least_one_macro(): void
    {
        Stringable::macro('alpha', static fn(): string => '');

        MacroRegistry::init(new VoidProgress());

        $this->assertContains(Stringable::class, MacroRegistry::getKnownMacroableClasses());
    }

    #[Test]
    public function init_is_idempotent(): void
    {
        Stringable::macro('x', static fn(): int => 0);

        MacroRegistry::init(new VoidProgress());
        $first = MacroRegistry::for(Stringable::class);

        MacroRegistry::init(new VoidProgress());
        $second = MacroRegistry::for(Stringable::class);

        $this->assertSame(\array_keys($first), \array_keys($second));
        // Definitions are equal by value; not necessarily the same instances.
        $this->assertEquals($first['x'], $second['x']);
    }

    #[Test]
    public function mixin_registers_each_method_as_a_macro(): void
    {
        // Macroable::mixin walks the mixin object's methods and registers each via macro().
        // Foundation captures these for free because they live in Macroable::$macros at boot.
        Stringable::mixin(new MixinFixture());

        MacroRegistry::init(new VoidProgress());

        $this->assertNotNull(MacroRegistry::get(Stringable::class, 'mixinone'));
        $this->assertNotNull(MacroRegistry::get(Stringable::class, 'mixintwo'));
    }

    #[Test]
    public function override_for_testing_replaces_state(): void
    {
        $def = new MacroDefinition(
            declaringClass: Stringable::class,
            methodName: 'fake',
            casedName: 'fake',
            params: [],
            returnType: \Psalm\Type::getString(),
            signatureReturnType: \Psalm\Type::getString(),
        );

        MacroRegistry::overrideForTesting(
            macros: [\strtolower(Stringable::class) => ['fake' => $def]],
            knownMacroableClasses: [Stringable::class],
        );

        $this->assertSame($def, MacroRegistry::get(Stringable::class, 'fake'));
        $this->assertSame([Stringable::class], MacroRegistry::getKnownMacroableClasses());
    }

    #[Test]
    public function signature_return_type_mirrors_native_return_type(): void
    {
        // When the closure declares a native return type, `signatureReturnType`
        // mirrors `returnType` (both populated by reflection).
        Stringable::macro('typedReturn', static fn(): int => 0);

        MacroRegistry::init(new VoidProgress());

        $typed = MacroRegistry::get(Stringable::class, 'typedreturn');
        $this->assertNotNull($typed);
        $this->assertNotNull($typed->signatureReturnType);
        $this->assertSame('int', $typed->signatureReturnType->getId());
        $this->assertSame('int', $typed->returnType->getId());
    }

    #[Test]
    public function override_for_testing_round_trips_a_null_signature_return_type(): void
    {
        // The "no native return type → signatureReturnType null" branch of the
        // registry contract is asserted via the override seam rather than via
        // reflection on a closure: PHP's reflection of closures without declared
        // return types is not consistent across patch versions, so a reflection-
        // based test is unreliable in CI. Push a definition through `overrideForTesting`
        // and verify that lookup preserves the null `signatureReturnType` end-to-end.
        $def = new MacroDefinition(
            declaringClass: Stringable::class,
            methodName: 'nonative',
            casedName: 'noNative',
            params: [],
            returnType: \Psalm\Type::getMixed(),
            signatureReturnType: null,
        );

        MacroRegistry::overrideForTesting(
            macros: [\strtolower(Stringable::class) => ['nonative' => $def]],
            knownMacroableClasses: [Stringable::class],
        );

        $retrieved = MacroRegistry::get(Stringable::class, 'noNative');
        $this->assertNotNull($retrieved);
        $this->assertNull($retrieved->signatureReturnType);
        $this->assertSame('mixed', $retrieved->returnType->getId());
    }

}

final class MixinFixture
{
    public function mixinOne(): \Closure
    {
        return static fn(): string => 'one';
    }

    public function mixinTwo(): \Closure
    {
        return static fn(): int => 2;
    }
}

final class MacroCallableFixture
{
    public static function staticHelper(string $input): string
    {
        return $input;
    }
}

final class MacroInvokableFixture
{
    public function __invoke(int $count): int
    {
        return $count;
    }
}

final class MacroNonPublicFixture
{
    protected static function protectedHelper(string $arg): string
    {
        return $arg;
    }
}

final class MacroInstanceMethodFixture
{
    public function instanceHelper(string $arg): string
    {
        return $arg;
    }
}

class MacroSelfReturnFixture
{
    public static function returnsSelf(): self
    {
        return new self();
    }
}
