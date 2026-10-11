<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/**
 * The part of a template's {@see TemplateContract} that a `view()` call site can be checked
 * against: the declared variables, whether the template's `@props` were fully readable, and the
 * set of variables its compiled body reads.
 *
 * Split from TemplateContract because only these facts survive into the shadow manifest.
 *
 * @psalm-immutable
 * @internal
 */
final class ViewDataContract
{
    /**
     * @param array<string, ContractVar> $vars          variable name (without $) => declaration
     * @param bool                       $propsUnknown  a `@props([...])` whose array was not fully
     *                                                  literal, so the declared set is a lower bound
     * @param list<string>               $readVariables variable names (without $) the compiled body
     *                                                  reads, ambient names excluded
     * @param bool                       $readsUnknown  the compiled body does something that hides
     *                                                  which names it reads, so $readVariables is a
     *                                                  lower bound. Defaults to true: a caller that
     *                                                  never computed a read set must not be read as
     *                                                  one that proved the set empty.
     * @param list<string>               $localVariables the subset of $readVariables the compiled
     *                                                  body binds for itself: a `@foreach` /
     *                                                  `@forelse` alias, an assignment target, a
     *                                                  closure parameter, a `catch` variable. They
     *                                                  stay IN $readVariables, because "does the
     *                                                  template use this name" is the right question
     *                                                  for UnusedViewData; they are not something a
     *                                                  call site is expected to pass.
     * @param list<string>               $rawDeclaredVariables names declared by a raw `@var`
     *                                                  docblock that did not become a contract var:
     *                                                  one for a name the template binds itself (a
     *                                                  type hint on a local). Consumed-only: they
     *                                                  make a passed key count as used and nothing
     *                                                  more.
     */
    public function __construct(
        public readonly array $vars,
        public readonly bool $propsUnknown,
        public readonly array $readVariables = [],
        public readonly bool $readsUnknown = true,
        public readonly array $localVariables = [],
        public readonly array $rawDeclaredVariables = [],
    ) {}
}
