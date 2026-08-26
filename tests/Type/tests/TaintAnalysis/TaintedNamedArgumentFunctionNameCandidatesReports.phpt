--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace TaintedNamedArgumentFunctionNameCandidatesLib {
    /** @psalm-taint-sink html $label */
    function sink(string $path = 'safe', string $label = 'x'): void { echo $label; }
}

namespace TaintedNamedArgumentFunctionNameCandidatesReports {
    use function TaintedNamedArgumentFunctionNameCandidatesLib\sink as aliasedSink;

    /** @psalm-taint-source input */
    function tainted(): string { return 'attacker'; }

    /** @psalm-taint-sink html $label */
    function localSink(string $path = 'safe', string $label = 'x'): void { echo $label; }

    /**
     * The two attribute-derived candidates in `functionNameCandidates()`, each of which is the
     * ONLY one that resolves for its own call shape. `Functions::getStorage()` looks its id up
     * as a key in file storage and reflection; it does not consult the file's alias table, so
     * dropping either attribute silently strips the matching call.
     *
     * `resolvedName`: an aliased call whose written name (`aliasedSink`) names no real function.
     * `namespacedName`: an unqualified call to a same-namespace function, which PHP-Parser
     * leaves without a `resolvedName` because PHP itself defers it to runtime.
     *
     * The third candidate, the raw written name, is pinned by
     * `TaintedNamedArgumentBuiltinFunctionPositionMatchReports.phpt` (a global builtin called
     * unqualified from inside a namespace).
     */
    function aliasedCallKeepsTaint(): void
    {
        aliasedSink(path: 'safe', label: tainted());
    }

    function unqualifiedSameNamespaceCallKeepsTaint(): void
    {
        localSink(path: 'safe', label: tainted());
    }
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedHtml on line %d: Detected tainted HTML
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
