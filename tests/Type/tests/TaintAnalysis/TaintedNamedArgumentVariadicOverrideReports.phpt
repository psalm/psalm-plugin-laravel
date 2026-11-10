--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--XFAIL--
Psalm 7.0.0-rc28 resolves a named argument a parent's variadic captures to the override's variadic, not to the override's parameter of that name (regression from vimeo/psalm#12760); tracked upstream as vimeo/psalm#13013, still failing on 7.2.1
--FILE--
<?php declare(strict_types=1);

namespace TaintedNamedArgumentVariadicOverrideReports;

/** @psalm-taint-source input */
function tainted(): string { return 'attacker'; }

// The handler must NOT strip a named argument whose call may dispatch to an override that binds it
// to a fixed parameter: PHP binds `sink:` by name to the override's `$sink`, a genuine sink.

class Base
{
    /** @psalm-impure */
    public function go(string ...$rest): void {}

    /** @psalm-impure */
    public static function s(string ...$rest): void {}

    /**
     * @psalm-impure
     * @psalm-suppress UnusedParam
     */
    private static function p(string ...$rest): void {}

    public static function viaStatic(): void
    {
        static::s(sink: tainted());
    }

    public static function viaStaticPrivate(): void
    {
        static::p(sink: tainted());
    }
}

class Child extends Base
{
    /** @psalm-suppress ParamNameMismatch */
    #[\Override]
    public function go(string $sink = '', string ...$rest): void { system($sink); }

    /** @psalm-suppress ParamNameMismatch */
    #[\Override]
    public static function s(string $sink = '', string ...$rest): void { system($sink); }

    public static function p(string $sink = '', string ...$rest): void { system($sink); }
}

/**
 * A single receiver class only bounds an instance call, and `static::` is late-bound; a private
 * parent method does not pin `static::` (PHP dispatches to the child's public method).
 */
function inexactDispatch(Base $receiver): void
{
    $receiver->go(sink: tainted());
    Child::viaStatic();
    Child::viaStaticPrivate();
}
?>
--EXPECTF--
TaintedShell on line %d: Detected tainted shell code
TaintedShell on line %d: Detected tainted shell code
TaintedShell on line %d: Detected tainted shell code
