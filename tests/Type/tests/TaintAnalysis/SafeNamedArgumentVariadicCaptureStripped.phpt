--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace SafeNamedArgumentVariadicCaptureStrippedLib {
    /** @psalm-taint-sink file $path */
    function sink(string $path = 'safe', mixed ...$extra): void { if ($extra === []) { echo $path; } }
}

namespace SafeNamedArgumentVariadicCaptureStripped {
    use function SafeNamedArgumentVariadicCaptureStrippedLib\sink as aliasedSink;

    /** @psalm-taint-source input */
    function tainted(): string { return 'attacker'; }

    /**
     * Without the strip plain Psalm reports each call below against a fixed parameter the value
     * never reaches (vimeo/psalm#12251): the variadic's node is keyed by the written offset. Each
     * callee has its own sink so no shape can mask another.
     *
     * @psalm-taint-sink file $a
     * @psalm-taint-sink html $rest
     */
    function v(string $a = '', string $b = '', string ...$rest): void
    {
        echo $a;
        echo $b;

        foreach ($rest as $chunk) {
            echo $chunk;
        }
    }

    /** @psalm-taint-sink file $path */
    function localSink(string $path = 'safe', mixed ...$extra): void { if ($extra === []) { echo $path; } }

    final class Mailer
    {
        /** @psalm-taint-sink file $path */
        public function write(string $path = 'safe', mixed ...$extra): void { echo $path; }
    }

    final class Job
    {
        /** @psalm-taint-sink file $path */
        public function __construct(string $path = 'safe', mixed ...$extra) { echo $path; }
    }

    final class FinalMethodHolder
    {
        /** @psalm-taint-sink html $a */
        final public function write(string $a = '', string ...$rest): void { echo $a . \count($rest); }
    }

    final class PrivateMethodHolder
    {
        /** @psalm-taint-sink file $a */
        private function write(string $a = '', string ...$rest): void { echo $a . \count($rest); }

        public function go(): void
        {
            $this->write(zzz: tainted());
        }
    }

    enum Level
    {
        case Low;

        /** @psalm-taint-sink shell $a */
        public function write(string $a = '', string ...$rest): void { echo $a . \count($rest); }
    }

    class ParentWriter
    {
        /** @psalm-taint-sink shell $a */
        public static function emit(string $a = '', string ...$rest): void { echo $a . \count($rest); }
    }

    class ChildWriter extends ParentWriter
    {
        public static function go(): void
        {
            parent::emit(zzz: tainted());
        }
    }

    final class NullsafeAction
    {
        public function run(string $a = '', string ...$rest): void { echo $a . \count($rest); }
    }

    function maybeAction(bool $present): ?NullsafeAction
    {
        return $present ? new NullsafeAction() : null;
    }

    /** An unmatched name, and the variadic's own name, are both captured by `$rest`. */
    function unmatchedAndOwnName(): void
    {
        v(zzz: tainted());
        v(rest: tainted());
    }

    /** `resolvedName` (aliased import) and `namespacedName` (unqualified same-namespace call). */
    function functionNameCandidates(): void
    {
        aliasedSink(zzz: tainted());
        localSink(zzz: tainted());
    }

    /** A `$var` receiver of one known class, and a constructor. */
    function receiverAndConstructor(Mailer $mailer): void
    {
        $mailer->write(zzz: tainted());
        new Job(zzz: tainted());
    }

    /** Exact late-bound dispatch: a final method, a private method on an instance call, an enum. */
    function exactDispatch(FinalMethodHolder $holder, Level $level): void
    {
        $holder->write(zzz: tainted());
        $level->write(zzz: tainted());
    }

    /** A nullsafe call on a chained receiver resolves via Psalm's virtual variable. */
    function nullsafeChainedReceiver(bool $present): void
    {
        maybeAction($present)?->run(zzz: tainted());
    }
}
?>
--EXPECTF--
