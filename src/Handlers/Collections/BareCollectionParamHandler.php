<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Collections;

use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;

/**
 * Makes a bare collection parameter type (`Collection`, `?Collection`, `Eloquent\Collection`,
 * `LazyCollection`, `Enumerable`, a templated subclass) accept any instantiation, so
 * `function f(Collection $c)` takes a `Collection<int, Dog>` the way a bare `array` takes any array.
 *
 * Psalm expands a bare class with bounded templates to `Collection<array-key, mixed>` when it checks
 * an argument ({@see \Psalm\Internal\Type\TypeExpander::expandNamedObject()}). Only the `mixed` slots
 * of that expansion are marked "omitted" (`Union::$had_template`), which skips the invariant check
 * of the argument; a bounded slot (`array-key`, `Model`) is compared invariantly and rejects
 * `Collection<int, Dog>`. Psalm keeps that on purpose (vimeo/psalm#5172, #5191).
 *
 * After population this handler rewrites each bare collection inside a parameter type into the
 * generic Psalm would expand it to, with every argument marked omitted. The callee body keeps the
 * same bounds (`Collection<array-key, mixed>`); an explicit `Collection<string, Dog>` is never touched,
 * and neither is a bare generic that is not a collection.
 *
 * Scope: `FunctionLikeParameter::$type` (and `$signature_type` when it is the same object) of every method (incl. pseudo methods), function and closure
 * known to the codebase, descending through type arguments and array and object shapes. Callable and
 * closure parameter types are left alone: probes show no verdict changes when they are rewritten.
 * Property and return types are unaffected.
 *
 * Accepted hole: a callee may put a key or value outside the caller's instantiation
 * (`$c->put('x', $y)` on a `Collection<int, Dog>`), as Psalm already accepts for the `mixed` value.
 *
 * Idempotent: a rewritten type is a generic and no longer matches.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1885
 */
final class BareCollectionParamHandler implements AfterCodebasePopulatedInterface
{
    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $codebase = $event->getCodebase();
        $rewriter = new BareCollectionParamRewriter($codebase);

        foreach ($codebase->classlike_storage_provider::getAll() as $storage) {
            foreach ([$storage->methods, $storage->pseudo_methods, $storage->pseudo_static_methods] as $methods) {
                foreach ($methods as $method) {
                    $rewriter->rewrite($method);
                }
            }
        }

        // Closures live in their file's storage next to named functions.
        foreach ($codebase->file_storage_provider::getAll() as $fileStorage) {
            foreach ($fileStorage->functions as $function) {
                $rewriter->rewrite($function);
            }
        }

        foreach ($codebase->functions->getAllStubbedFunctions() as $function) {
            $rewriter->rewrite($function);
        }
    }
}
