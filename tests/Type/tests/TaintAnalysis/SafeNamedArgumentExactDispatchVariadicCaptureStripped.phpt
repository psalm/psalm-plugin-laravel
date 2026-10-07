--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace SafeNamedArgumentExactDispatchVariadicCaptureStripped;

/** @psalm-taint-source input */
function tainted(): string { return 'attacker'; }

class FinalMethodHolder
{
    /**
     * @psalm-taint-sink html $a
     * @psalm-impure
     */
    final public function write(string $a = '', string ...$rest): void { echo $a; }
}

class PrivateMethodHolder
{
    /**
     * @psalm-taint-sink file $a
     * @psalm-impure
     * @psalm-suppress UnusedParam
     */
    private function write(string $a = '', string ...$rest): void { echo $a; }

    public function go(): void
    {
        $this->write(zzz: tainted());
    }
}

enum Level
{
    case Low;

    /**
     * @psalm-taint-sink shell $a
     * @psalm-impure
     */
    public function write(string $a = '', string ...$rest): void { echo $a; }
}

class ParentWriter
{
    /**
     * @psalm-taint-sink shell $a
     * @psalm-impure
     */
    public static function emit(string $a = '', string ...$rest): void { echo $a; }
}

class ChildWriter extends ParentWriter
{
    public static function go(): void
    {
        parent::emit(zzz: tainted());
    }
}

/**
 * Dispatch is exact in each shape below, so the variadic capture is provable and the `zzz:`
 * value is stripped (Psalm alone would mis-report it against the fixed `$a` sink, vimeo/psalm#12251):
 * a final method on a non-final class, a private method, an enum (implicitly final), and an explicit
 * `parent::` call. Guards the exact-dispatch gate against declining too much.
 */
function exactInstanceDispatchIsStripped(FinalMethodHolder $holder, Level $level): void
{
    $holder->write(zzz: tainted());
    $level->write(zzz: tainted());
}
?>
--EXPECTF--
