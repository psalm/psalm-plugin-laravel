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
 * the docblock `@return static` form, so late static binding survives a fluent chain and subclass
 * methods don't surface as false UndefinedMagicMethod (#1216).
 *
 * Upstream Psalm bug (vimeo/psalm #5752 / #4406): `TypeExpander::expandNamedObject()` rebuilds a
 * native `: static` on a GENERIC declaring class into a `TGenericObject` without carrying
 * `is_static`, collapsing the chain to `Parent<bound>`. The docblock form
 * (`TNamedObject('static', from_docblock: true)`) skips that rebuild and resolves to the concrete
 * receiver, so the two spellings — identical in semantics — diverge in inference. Rewriting native
 * to docblock can only make `static` resolve more precisely, never widen it. `signature_return_type`
 * stays native (it feeds MethodComparator). `: self` is not late-static-bound and must NOT be
 * rewritten. Retire once TypeExpander carries `is_static` through the rebuild
 * (cf. ConsoleClosureScopeHandler, another auto-retiring upstream stopgap).
 *
 * Scoped to every class transitively extending Eloquent\Builder (matches
 * {@see BuilderSubclassQueryMixinHandler}), which also catches abstract intermediate builders no
 * model references directly.
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
