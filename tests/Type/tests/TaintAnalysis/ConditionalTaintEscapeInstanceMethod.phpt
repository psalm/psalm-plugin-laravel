--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

namespace ConditionalTaintEscapeInstanceMethod {
    /** @psalm-taint-source input */
    function tainted(): string { return 'attacker'; }

    /**
     * A conditional `@psalm-taint-escape` on an instance method applies since Psalm 7.2 (vimeo/psalm#12621),
     * so a stub can express an argument-dependent escape without a `RemoveTaintsInterface` handler.
     */
    final class Encoder
    {
        /**
         * @psalm-taint-escape ($mode is 1 ? 'html' : null)
         * @psalm-taint-escape ($mode is 1 ? 'has_quotes' : null)
         * @psalm-flow ($value) -> return
         */
        public function encode(string $value, int $mode): string { return $value; }
    }

    /** The literal selects the escape: nothing is reported, for a plain and a nullsafe receiver. */
    function literalModeEscapes(Encoder $encoder, ?Encoder $maybe): void
    {
        echo $encoder->encode(tainted(), 1);
        echo $maybe?->encode(tainted(), 1);
    }

    /** A runtime mode cannot select the escape: the flow is reported (false positive, never a silent pass). */
    function runtimeModeReports(Encoder $encoder, int $mode): void
    {
        echo $encoder->encode(tainted(), $mode);
    }

    /** A literal that selects no escape reports too. */
    function otherLiteralReports(Encoder $encoder): void
    {
        echo $encoder->encode(tainted(), 2);
    }
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
