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
     * An aliased call and an unqualified same-namespace call, each naming a sunk parameter with
     * `label:` written at offset 0. Neither callee declares a variadic, so
     * NamedArgumentTaintHandler leaves both alone. The same two call shapes pin the handler's
     * name resolution in
     * `SafeNamedArgumentFunctionNameCandidatesVariadicCaptureStripped.phpt`.
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
