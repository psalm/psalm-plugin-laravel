<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Internal;

use Illuminate\Database\Eloquent\Model;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\StatementsSource;
use Psalm\Type\Atomic\TNamedObject;

/**
 * Proof obligations shared by the Psalter fixes that rewrite a magic-forwarded Eloquent call
 * (`Post::make()`, `Post::where()`) into an explicit one (`new Post()`, `Post::query()->where()`).
 *
 * The rewrite is only behavior-preserving when the call really takes the stock route, so each helper answers
 * "can this be disproved?" and a fix is declined (the issue still reported) when it can't be proven.
 *
 * @internal
 */
final class ModelCallGuard
{
    /**
     * Whether the model's class chain (ancestors and traits included) declares any of $methods in a class other
     * than the framework `Model`. `declaring_method_ids` points at the using class for a trait method, so a trait
     * override is caught the same way. An unreadable class counts as overriding: a fix needs proof, a report does not.
     *
     * @param class-string<Model> $modelClass
     * @param list<lowercase-string> $methods
     *
     * @psalm-capabilities read-props
     */
    public static function overridesAny(Codebase $codebase, string $modelClass, array $methods): bool
    {
        try {
            $classStorage = $codebase->classlike_storage_provider->get(\strtolower($modelClass));
        } catch (\InvalidArgumentException) {
            return true;
        }

        foreach ($methods as $method) {
            $declaring = $classStorage->declaring_method_ids[$method] ?? null;

            if ($declaring !== null && \strtolower($declaring->fq_class_name) !== 'illuminate\\database\\eloquent\\model') {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether `Model::method()` written here can run as `$this->__call()`. PHP keeps the object context of a
     * named static call when `$this` is an instance of the named class, so the instance's class, table and
     * connection apply, while `new Model()` / `Model::query()` always build a fresh model. That is possible in a
     * non-static method or closure whose `$this` is the model, one of its subclasses, or one of its ancestors (the
     * base class's `$this` can be the model at runtime). A trait or an unreadable `$this` cannot be ruled out. A
     * static method or a static closure has no object context, even though Psalm still lists `$this` in a static
     * method's scope.
     *
     * @param class-string<Model> $modelClass
     */
    public static function mayRunInObjectContextOf(Codebase $codebase, string $modelClass, StatementsSource $source, Context $context): bool
    {
        $thisType = $context->vars_in_scope['$this'] ?? null;

        if ($thisType === null || $source->isStatic()) {
            return false;
        }

        foreach ($thisType->getAtomicTypes() as $atomicType) {
            if (!$atomicType instanceof TNamedObject) {
                return true;
            }

            $thisClass = $atomicType->value;

            if (\strcasecmp($thisClass, $modelClass) === 0) {
                return true;
            }

            try {
                $thisStorage = $codebase->classlike_storage_provider->get(\strtolower($thisClass));

                if (
                    $thisStorage->is_trait
                    || $thisStorage->is_interface
                    || $codebase->classExtends($thisClass, $modelClass)
                    || $codebase->classExtends($modelClass, $thisClass)
                ) {
                    return true;
                }
            } catch (\InvalidArgumentException|UnpopulatedClasslikeException) {
                return true;
            }
        }

        return false;
    }
}
