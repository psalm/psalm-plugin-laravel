<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/**
 * The part of a template's {@see TemplateContract} that a `view()` call site can be checked
 * against: the declared variables and whether the template's `@props` were fully readable.
 *
 * Split from TemplateContract because only these two facts survive into the shadow manifest.
 * The rest of TemplateContract (the read-variable set, the suppression map) is derived from the
 * compiled output and is either recomputed or already persisted in its own manifest slot.
 *
 * @psalm-immutable
 * @internal
 */
final class ViewDataContract
{
    /**
     * @param array<string, ContractVar> $vars         variable name (without $) => declaration
     * @param bool                       $propsUnknown a `@props([...])` whose array was not fully
     *                                                 literal, so the declared set is a lower bound
     */
    public function __construct(
        public readonly array $vars,
        public readonly bool $propsUnknown,
    ) {}
}
