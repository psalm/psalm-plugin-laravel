--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace TaintedNamedArgumentFixedParamReportsLib {
    /** @psalm-taint-sink html $label */
    function sink(string $path = 'safe', string $label = 'x'): void { echo $label; }
}

namespace TaintedNamedArgumentFixedParamReports {
    use function TaintedNamedArgumentFixedParamReportsLib\sink as aliasedSink;

    /** @psalm-taint-source input */
    function tainted(): string { return 'attacker'; }

    // Psalm 7.0.0-rc1 keys a named argument by the DECLARED index of its parameter, so none of
    // these callees has a variadic to strip: each shape must report at `$label` (or `$a`) only,
    // never at the parameter declared at the written offset. One sink per shape keeps emission
    // locations distinct.
    /** @psalm-taint-sink html $label */
    function sinkMismatch(string $path = 'safe', string $label = 'x'): void { echo $label; }

    /** @psalm-taint-sink html $label */
    function sinkMatch(string $path = 'safe', string $label = 'x'): void { echo $label; }

    /** @psalm-taint-sink html $label */
    function sinkPositional(string $path = 'safe', string $label = 'x'): void { echo $label; }

    /** @psalm-taint-sink html $label */
    function localSink(string $path = 'safe', string $label = 'x'): void { echo $label; }

    function reordered(string $a = '', string $b = ''): void { echo $a . \strlen($b); }

    /** `$path` is declared before `$label`'s trailing variadic; the name binds to `$label`. */
    function sinkWithTail(string $path = 'safe', string $label = 'x', string ...$rest): void
    {
        echo $path;
        echo $label . \count($rest);
    }

    final class Sink
    {
        /** @psalm-taint-sink html $label */
        public function report(string $path = 'safe', string $label = 'x'): void { echo $label; }

        public static function reordered(string $a = '', string $b = ''): void { echo $a . \strlen($b); }
    }

    final class UnresolvedSink
    {
        /** @psalm-taint-sink html $label */
        public function report(string $path = 'safe', string $label = 'x'): void { echo $label; }
    }

    final class Ctor
    {
        /** @psalm-taint-sink html $label */
        public function __construct(string $path = 'safe', string $label = 'x') { echo $label; }
    }

    abstract class Base
    {
        /** @psalm-taint-sink html $label */
        public static function report(string $path = 'safe', string $label = 'x'): void { echo $label; }

        /** @psalm-taint-sink html $label */
        public static function relay(string $path = 'safe', string $label = 'x'): void { echo $label; }

        public static function viaSelf(): void
        {
            self::relay(path: 'safe', label: tainted());
        }

        public static function viaStatic(): void
        {
            static::relay(path: 'safe', label: tainted());
        }
    }

    final class Child extends Base {}

    final class Writer
    {
        /** @psalm-taint-sink html $path */
        public function store(string $path): void { echo $path; }
    }

    final class OtherWriter
    {
        /** @psalm-taint-sink file $path */
        public function store(string $path): void { echo \strlen($path); }
    }

    function positionShapes(): void
    {
        sinkMismatch(label: tainted());
        sinkMatch(path: 'safe', label: tainted());
        sinkPositional('safe', tainted());
        reordered(b: 'safe', a: tainted());
        Sink::reordered(b: 'safe', a: tainted());
        sinkWithTail(label: tainted());
    }

    function resolutionShapes(Sink $sink, Writer|OtherWriter $writer): void
    {
        $sink->report(label: tainted());
        (new UnresolvedSink())->report(label: tainted());
        aliasedSink(path: 'safe', label: tainted());
        localSink(path: 'safe', label: tainted());
        $writer->store(path: tainted());
    }

    function constructorAndStaticShapes(): void
    {
        new Ctor(path: 'safe', label: tainted());
        Base::report(path: 'safe', label: tainted());
        Child::report(path: 'safe', label: tainted());
        Base::viaSelf();
        Base::viaStatic();
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
TaintedHtml on line %d: Detected tainted HTML
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedHtml on line %d: Detected tainted HTML
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedHtml on line %d: Detected tainted HTML
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedHtml on line %d: Detected tainted HTML
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedHtml on line %d: Detected tainted HTML
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedHtml on line %d: Detected tainted HTML
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedHtml on line %d: Detected tainted HTML
TaintedHtml on line %d: Detected tainted HTML
TaintedHtml on line %d: Detected tainted HTML
TaintedHtml on line %d: Detected tainted HTML
TaintedHtml on line %d: Detected tainted HTML
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedHtml on line %d: Detected tainted HTML
TaintedHtml on line %d: Detected tainted HTML
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedHtml on line %d: Detected tainted HTML
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedFile on line %d: Detected tainted file handling
