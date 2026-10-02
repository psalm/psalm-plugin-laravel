--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace SafeNamedArgumentFunctionNameCandidatesVariadicCaptureLib {
    /** @psalm-taint-sink file $path */
    function sink(string $path = 'safe', mixed ...$extra): void { if ($extra === []) { echo $path; } }
}

namespace SafeNamedArgumentFunctionNameCandidatesVariadicCaptureStripped {
    use function SafeNamedArgumentFunctionNameCandidatesVariadicCaptureLib\sink as aliasedSink;

    /** @psalm-taint-source input */
    function tainted(): string { return 'attacker'; }

    /** @psalm-taint-sink file $path */
    function localSink(string $path = 'safe', mixed ...$extra): void { if ($extra === []) { echo $path; } }

    /**
     * The two attribute-derived candidates in `functionNameCandidates()`, each of which is the
     * ONLY one that resolves for its own call shape. `Functions::getStorage()` looks its id up as
     * a key in file storage and reflection; it does not consult the file's alias table.
     *
     * `resolvedName`: an aliased call whose written name (`aliasedSink`) names no real function.
     * `namespacedName`: an unqualified call to a same-namespace function, which PHP-Parser leaves
     * without a `resolvedName` because PHP itself defers it to runtime.
     *
     * Both calls name no declared parameter, so the value is captured by the variadic and its
     * node is keyed by the WRITTEN offset, colliding with `$path`'s `file` sink at offset 0. Drop
     * either candidate and the callee stops resolving, the capture can no longer be proven, and
     * the mis-attributed `TaintedFile` comes back. A separate sink function per candidate so
     * neither can mask the other. The third candidate, the raw written name, is a global builtin
     * called unqualified from a namespace and has no variadic fixture.
     */
    function aliasedCallCaptureIsStripped(): void
    {
        aliasedSink(zzz: tainted());
    }

    function unqualifiedSameNamespaceCallCaptureIsStripped(): void
    {
        localSink(zzz: tainted());
    }
}
?>
--EXPECTF--
