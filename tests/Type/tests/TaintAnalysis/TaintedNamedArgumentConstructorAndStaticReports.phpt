--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace TaintedNamedArgumentConstructorAndStaticReports;

/** @psalm-taint-source input */
function tainted(): string { return 'attacker'; }

final class Ctor
{
    /** @psalm-taint-sink html $label */
    public function __construct(string $path = 'safe', string $label = 'x')
    {
        echo $label;
    }
}

abstract class Base
{
    /** @psalm-taint-sink html $label */
    public static function report(string $path = 'safe', string $label = 'x'): void
    {
        echo $label;
    }

    public static function viaSelf(): void
    {
        self::report(path: 'safe', label: tainted());
    }

    public static function viaStatic(): void
    {
        static::report(path: 'safe', label: tainted());
    }
}

final class Child extends Base {}

/**
 * `New_` and `StaticCall` callees resolve through `resolveClassNamePart()`, including the
 * `self::` / `static::` special names, which map to the enclosing class. `label:` names the
 * declared parameter at its own written offset in every call, so upstream attributes each one
 * correctly and none may be stripped.
 */
function constructorAndStaticNamedArgumentsKeepTaint(): void
{
    new Ctor(path: 'safe', label: tainted());
    Base::report(path: 'safe', label: tainted());
    Child::report(path: 'safe', label: tainted());
    Base::viaSelf();
    Base::viaStatic();
}
?>
--EXPECTF--
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
