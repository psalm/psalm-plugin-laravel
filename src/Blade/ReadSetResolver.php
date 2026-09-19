<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/**
 * Closes a template's consumed-variable set over its `@include` / `@extends` chain: every
 * scope-passing directive hands the whole of the including template's data down, so a variable a
 * partial reads — or declares — is a variable the outermost `view()` call site legitimately passes.
 *
 * Declines (null) the moment any template in the chain leaves its own contribution a lower bound —
 * an unknowable read set, a dynamic include, an include naming a template the compile pass never
 * registered. A lower bound cannot prove a key unused, and the whole point of the rule is that its
 * false-positive rate is zero.
 *
 * @internal
 */
final class ReadSetResolver
{
    /**
     * @return array<string, true>|null variable names the chain reads or declares, or null when
     *                                  nothing about it is provable
     */
    public static function reads(string $viewName): ?array
    {
        $visited = [];

        return self::walk($viewName, $visited);
    }

    /**
     * @param array<string, true> $visited passed by reference so a diamond or a cycle in the include
     *        graph is walked once. A revisit contributing nothing is correct: the caller is building
     *        one union, and the first visit already folded that template's reads into it.
     *
     * @return array<string, true>|null
     */
    private static function walk(string $viewName, array &$visited): ?array
    {
        if (isset($visited[$viewName])) {
            return [];
        }

        $visited[$viewName] = true;

        $contract = ContractRegistry::contractFor($viewName);

        if (!$contract instanceof ViewDataContract || $contract->readsUnknown) {
            return null;
        }

        $dataIncludes = ContractRegistry::dataIncludesFor($viewName);

        if ($dataIncludes === null || $dataIncludes[1]) {
            return null;
        }

        // Declared names count as consumed wherever they are declared, not just at the top of the
        // chain: `{{-- @var --}}` / `@props` is a template's stated interface, and reporting a key an
        // included partial declares but has not got round to reading yet would contradict the
        // "neither reads nor declares" semantics the call-site check already applies to the outermost
        // template.
        $reads = \array_fill_keys($contract->readVariables, true)
            + \array_fill_keys(\array_keys($contract->vars), true);

        foreach ($dataIncludes[0] as $included) {
            $nested = self::walk($included, $visited);

            if ($nested === null) {
                return null;
            }

            $reads += $nested;
        }

        return $reads;
    }
}
