--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace TaintedNamedArgumentVariadicDeclinedReports;

/** @psalm-taint-source input */
function tainted(): string { return 'attacker'; }

// The handler must NOT strip a named argument it cannot prove reaches the variadic: each shape
// below has a genuine sink at a fixed-parameter override (or component) that plain Psalm reports.

abstract class AbstractHandler
{
    /** @psalm-impure */
    abstract public function go(string ...$args): void;
}

final class ConcreteHandler extends AbstractHandler
{
    /** @psalm-suppress ParamNameMismatch */
    #[\Override]
    public function go(string $sink = ''): void { system($sink); }
}

interface HandlerContract
{
    /** @psalm-impure */
    public function dispatch(string ...$args): void;
}

final class ConcreteContract implements HandlerContract
{
    /** @psalm-suppress ParamNameMismatch */
    #[\Override]
    public function dispatch(string $sink = ''): void { system($sink); }
}

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

interface HtmlWriter
{
    /**
     * @psalm-taint-sink html $label
     * @psalm-impure
     */
    public function write(string $path = '', string $label = ''): void;
}

class VariadicWriter
{
    /** @psalm-impure */
    final public function write(string $path = '', string ...$label): void {}
}

interface OtherHtmlWriter
{
    /**
     * @psalm-taint-sink html $label
     * @psalm-impure
     */
    public function report(string $path = '', string $label = ''): void;
}

class OtherVariadicWriter
{
    /** @psalm-impure */
    final public function report(string $path = '', string ...$label): void {}
}

/** An abstract class or interface method has no body to re-spread. */
function bodilessMethods(AbstractHandler $handler, HandlerContract $contract): void
{
    $handler->go(sink: tainted());
    $contract->dispatch(sink: tainted());
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

/**
 * An intersection is one union member whose primary component is Psalm's choice, not the written
 * order. The variadic component is a concrete class so the bodiless decline cannot mask the guard.
 */
function intersectionBothOrders(VariadicWriter&HtmlWriter $a, OtherHtmlWriter&OtherVariadicWriter $b): void
{
    $a->write(label: tainted());
    $b->report(label: tainted());
}
?>
--EXPECTF--
TaintedShell on line %d: Detected tainted shell code
TaintedShell on line %d: Detected tainted shell code
TaintedShell on line %d: Detected tainted shell code
TaintedShell on line %d: Detected tainted shell code
TaintedShell on line %d: Detected tainted shell code
TaintedHtml on line %d: Detected tainted HTML
TaintedHtml on line %d: Detected tainted HTML
