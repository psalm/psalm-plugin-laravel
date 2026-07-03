<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Rewrites a native `: static` return (no docblock `@return`) on Eloquent\Builder subclasses into
 * the docblock `@return static` representation, so late-static-binding survives when the method is
 * reached through a fluent chain.
 *
 * Symptom: a method declared on a concrete custom builder (e.g. `ArtistBuilder::withPublicRating()`)
 * surfaces as a false UndefinedMagicMethod when reached via `->accessible()->when(...)->...`, because
 * the intermediate collapses from the concrete `ArtistBuilder` to its abstract generic parent.
 *
 * Upstream layer: the collapse is a vimeo/psalm design limitation, not a Laravel one — the correct
 * fix belongs in Psalm. `Internal\Type\TypeExpander::expandNamedObject()` rebuilds a native `: static`
 * return (stored as `TNamedObject(value=<declaring class>, is_static=true)`) into a fresh
 * `TGenericObject(<declaring class>, [template bounds])` WITHOUT carrying `is_static`, destroying the
 * late-static binding so the type collapses to `Parent<bound>`. A docblock `@return static` is stored
 * instead as `TNamedObject(value='static', from_docblock=true)`; that form skips the generic-collapse
 * branch (`classOrInterfaceExists('static')` is false), so `static` resolves to the concrete receiver.
 * Only native `: static` with NO docblock, on a GENERIC declaring class, is affected. See
 * vimeo/psalm #5752 / #4406 ("class-level templates and static should not mix").
 *
 * Compensating plugin layer: at population time we replace the native-`static` atomic with the
 * docblock-`static` atomic on the method's effective `return_type`. `signature_return_type` is left
 * native (it feeds MethodComparator, and the working docblock-`static` case keeps a native signature).
 * Semantics-preserving: native `: static` and `@return static` denote the same type; the rewrite can
 * only make `static` resolve to the concrete receiver, never widen it. `self` returns are NOT touched
 * — `: self` is not late-static-bound and resolves fine on the concrete child. Retire this handler
 * once the upstream TypeExpander carries `is_static` through that rebuild (cf. ConsoleClosureScopeHandler,
 * another auto-retiring stopgap for an upstream Psalm gap).
 *
 * Scoped broad to every class that transitively extends Eloquent\Builder (matches
 * {@see BuilderSubclassQueryMixinHandler} and the #1140 precedent), which also catches abstract
 * intermediate builders that declare the `static`-returning method but no model references directly.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1216
 */
final class BuilderNativeStaticReturnTypeHandler implements AfterCodebasePopulatedInterface
{
    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $codebase = $event->getCodebase();
        $builderLower = \strtolower(Builder::class);

        foreach ($codebase->classlike_storage_provider::getAll() as $storage) {
            // Only classes that transitively extend Eloquent\Builder — including abstract
            // intermediates a model never references directly (the declaring class of the
            // `static`-returning method may be exactly such an intermediate).
            if (!isset($storage->parent_classes[$builderLower])) {
                continue;
            }

            foreach ($storage->methods as $method_storage) {
                $return_type = $method_storage->return_type;
                if ($return_type === null) {
                    continue;
                }

                $atomics = $return_type->getAtomicTypes();

                // Rebuild the Union only when a native-static atomic is present, so methods
                // without one keep their original Union (and its metadata) untouched.
                $has_native_static = false;
                foreach ($atomics as $atomic) {
                    if (self::isNativeStaticReturn($atomic)) {
                        $has_native_static = true;
                        break;
                    }
                }

                if (!$has_native_static) {
                    continue;
                }

                // array_map over the non-empty atomic list keeps the result non-empty, so the
                // Union ctor needs no coercion. Only the native-static atomic is replaced;
                // `?static` (static|null) keeps its null atomic as-is.
                $method_storage->return_type = new Union(\array_map(
                    static fn(Atomic $atomic): Atomic => self::isNativeStaticReturn($atomic)
                        // The docblock `@return static` representation: value='static',
                        // from_docblock=true (mirrors how TypeParser stores `@return static`).
                        ? new TNamedObject('static', false, false, [], true)
                        : $atomic,
                    $atomics,
                ));
            }
        }
    }

    /**
     * Match ONLY a native `: static` return: a plain TNamedObject flagged is_static, not yet
     * resolved, whose value is the declaring-class FQN (native form) rather than the literal
     * 'static' (docblock form), and carrying no intersection types.
     *
     * @psalm-pure
     */
    private static function isNativeStaticReturn(Atomic $atomic): bool
    {
        return $atomic instanceof TNamedObject
            && $atomic::class === TNamedObject::class
            && $atomic->is_static
            && !$atomic->is_static_resolved
            && !$atomic->from_docblock
            && \strtolower($atomic->value) !== 'static'
            && !$atomic->extra_types;
    }
}
