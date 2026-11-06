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

        return self::walk($viewName, $visited, true);
    }

    /**
     * The raw `@var` declarations of the template that nothing in its include chain reads. Empty when
     * the read set is only a lower bound (see the class docblock) or the template's `@props` were not
     * readable, so a stale declaration is never reported on a guess.
     *
     * A declaration in an included partial is no read of the host's, and the other way round: only an
     * actual read anywhere in the chain keeps a declaration alive, because the chain shares one scope.
     *
     * @return list<string> declared variable names (without `$`)
     */
    public static function staleDeclarations(string $viewName): array
    {
        $contract = ContractRegistry::contractFor($viewName);

        if (!$contract instanceof ViewDataContract || $contract->propsUnknown || $contract->vars === []) {
            return [];
        }

        $visited = [];
        $reads = self::walk($viewName, $visited, false);

        if ($reads === null) {
            return [];
        }

        // The read set never carries `$this` or a `__`-prefixed name, so their absence proves nothing.
        return \array_values(\array_filter(
            \array_keys($contract->vars),
            static fn(string $name): bool => !isset($reads[$name]) && !ContractParser::readSetOmits($name),
        ));
    }

    /**
     * @param array<string, true> $visited passed by reference so a diamond or a cycle in the include
     *        graph is walked once. A revisit contributing nothing is correct: the caller is building
     *        one union, and the first visit already folded that template's reads into it.
     * @param bool $declarationsCount whether a declared name counts as consumed ({@see self::reads()})
     *        or only a name the compiled body reads does ({@see self::staleDeclarations()})
     *
     * @return array<string, true>|null
     */
    private static function walk(string $viewName, array &$visited, bool $declarationsCount): ?array
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

        $reads = \array_fill_keys($contract->readVariables, true);

        // Declared names count as consumed wherever they are declared, not just at the top of the
        // chain: a raw `@var` docblock and `@props` are a template's stated interface, and reporting a key an
        // included partial declares but has not got round to reading yet would contradict the
        // "neither reads nor declares" semantics the call-site check already applies to the outermost
        // template. A declaration that is not a contract (a local's type hint) counts here
        // too. A stale-declaration walk is asking whether the declaration is read, so it leaves them out.
        if ($declarationsCount) {
            $reads += \array_fill_keys(\array_keys($contract->vars), true)
                + \array_fill_keys($contract->rawDeclaredVariables, true);
        }

        foreach ($dataIncludes[0] as $included) {
            $nested = self::walk($included, $visited, $declarationsCount);

            if ($nested === null) {
                return null;
            }

            $reads += $nested;
        }

        return $reads;
    }
}
