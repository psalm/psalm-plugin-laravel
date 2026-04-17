--FILE--
<?php declare(strict_types=1);

// Regression for #750: `app(static::class, ...)` / `resolve(static::class, ...)`
// previously returned `mixed`, cascading into MixedAssignment and MixedMethodCall
// across Laravel ecosystem codebases (Filament, Livewire, Nova, Spatie packages).

abstract class Widget
{
    public function configure(): static
    {
        return $this;
    }

    // Mirrors Filament's static factory pattern: the factory resolves through the
    // container so subclasses can swap bindings or constructor args without
    // overriding the factory.
    public static function makeViaApp(string $label): static
    {
        $static = app(static::class, ['label' => $label]);
        /** @psalm-check-type-exact $static = Widget&static */
        return $static->configure();
    }

    public static function makeViaResolve(string $label): static
    {
        $static = resolve(static::class, ['label' => $label]);
        /** @psalm-check-type-exact $static = Widget&static */
        return $static;
    }

    // The method-return-type provider path: ContainerHandler::getMethodReturnType on
    // `Application::make()` and `Application::makeWith()` goes through the same resolver.
    public static function makeViaContainerMake(): static
    {
        $static = app()->make(static::class);
        /** @psalm-check-type-exact $static = Widget&static */
        return $static;
    }

    public static function makeViaContainerMakeWith(string $label): static
    {
        $static = app()->makeWith(static::class, ['label' => $label]);
        /** @psalm-check-type-exact $static = Widget&static */
        return $static;
    }

    // OffsetHandler::getMethodReturnType routes `offsetGet` through the same resolver,
    // so `$container[static::class]` should narrow identically.
    public static function makeViaOffsetGet(): static
    {
        $static = app()[static::class];
        /** @psalm-check-type-exact $static = Widget&static */
        return $static;
    }
}

// `app($classString)` where `$classString: class-string<Redirector>` resolves to
// Redirector. Covers dynamic class-string variables, not just `::class` literals.
/** @param class-string<\Illuminate\Routing\Redirector> $class */
function makeFromClassString(string $class): \Illuminate\Routing\Redirector
{
    return app($class);
}

// `class-string<T>` (template parameter) intentionally falls back to mixed.
// Resolving to the upper bound would trigger false InvalidReturnStatement at call
// sites that correctly return T, because the upper bound is a supertype of T.
/**
 * @template T of \Illuminate\Routing\Redirector
 */
final class TemplatedFactory
{
    /**
     * @param class-string<T> $class
     */
    public function make(string $class): void
    {
        $r = app($class);
        /** @psalm-check-type-exact $r = mixed */
        $r;
    }
}

?>
--EXPECTF--
MixedAssignment on line %d: Unable to determine the type that $r is being assigned to
UnusedVariable on line %d: $r is never referenced or the value is not used
