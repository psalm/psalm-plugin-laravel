--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

namespace ConditionalTaintEscapeConstructorKnownLimitation {
    /** @psalm-taint-source input */
    function tainted(): string { return 'attacker'; }

    /**
     * Psalm 7.2 applies a conditional `@psalm-taint-escape` to function, static and instance method
     * calls, but `NewAnalyzer` never reads `conditionally_removed_taints`, so on a constructor the escape
     * is parsed and then ignored. Use a `RemoveTaintsInterface` handler for an argument-dependent escape
     * on `new X(...)`.
     */
    final class Wrapper
    {
        public string $value;

        /**
         * @psalm-taint-escape ($mode is 1 ? 'html' : null)
         * @psalm-taint-escape ($mode is 1 ? 'has_quotes' : null)
         * @psalm-flow ($value) -> return
         */
        public function __construct(string $value, int $mode)
        {
            $this->value = $value;
        }
    }

    /** Reported although the literal `1` selects the escape on every other call shape. */
    function literalModeStillReports(): void
    {
        $wrapper = new Wrapper(tainted(), 1);
        echo $wrapper->value;
    }
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
