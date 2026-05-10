<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Providers;

use Psalm\Storage\FunctionLikeParameter;
use Psalm\Type\Union;

/**
 * A single discovered macro registered via {@see \Illuminate\Support\Traits\Macroable::macro()}
 * (or transitively via {@see \Illuminate\Support\Traits\Macroable::mixin()}).
 *
 * Foundation for issue #758. Strategy B (runtime reflection) only — type info is whatever
 * `ReflectionFunction` exposes for the registered closure (native param/return types only,
 * no docblock parsing yet). Strategy C (AST scan + docblock recovery) is a follow-up.
 *
 * Marked `@psalm-external-mutation-free` rather than `@psalm-immutable`: `Union` is
 * genuinely immutable, but `FunctionLikeParameter` exposes public mutable fields
 * (`sinks`, `attributes`, etc.). Anyone holding the `params` list could mutate an
 * entry in place. The contract in this codebase is "we never write through these
 * references"; downgrading the annotation matches that promise honestly instead of
 * advertising a structural guarantee Psalm can't enforce here.
 *
 * @psalm-external-mutation-free
 * @internal
 */
final class MacroDefinition
{
    /**
     * @param class-string $declaringClass The Macroable class that owns the `$macros` storage
     *                                     (the one that directly `use`s the trait, or — in the
     *                                     case of `\Illuminate\Database\Eloquent\Builder` —
     *                                     declares its own `$macros` static property and
     *                                     `macro()` method without using the trait).
     * @param lowercase-string $methodName Macro name lowercased. Used as the key into
     *                                     {@see \Psalm\Storage\ClassLikeStorage::$pseudo_methods}
     *                                     and `$pseudo_static_methods`, both of which Psalm
     *                                     keys lowercase.
     * @param string $casedName Original macro name as registered, preserving case. Used to
     *                          populate {@see \Psalm\Storage\FunctionLikeStorage::$cased_name}
     *                          which Psalm renders verbatim in diagnostics. Distinct from
     *                          `$methodName` so error messages show `countCharsTest` rather
     *                          than `countcharstest`.
     * @param list<FunctionLikeParameter> $params Parameter list inferred from the closure.
     * @param Union $returnType Return type for {@see \Psalm\Storage\FunctionLikeStorage::$return_type}.
     *                          Defaults to `mixed` when the closure has no native return type.
     * @param Union|null $signatureReturnType Return type for
     *                          {@see \Psalm\Storage\FunctionLikeStorage::$signature_return_type}.
     *                          `null` when the closure has no native return type — Psalm's
     *                          convention is that `signature_return_type` is set ONLY for
     *                          actual native PHP signatures, not for the docblock-derived
     *                          `mixed` fallback. Conflating them would mis-report the
     *                          synthesised method as having a native `mixed` signature.
     *
     * @psalm-external-mutation-free
     * @psalm-mutation-free
     */
    public function __construct(
        public readonly string $declaringClass,
        public readonly string $methodName,
        public readonly string $casedName,
        public readonly array $params,
        public readonly Union $returnType,
        public readonly ?Union $signatureReturnType,
    ) {}
}
