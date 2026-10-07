--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace TaintedNamedArgumentIntersectionReceiverReports;

/** @psalm-taint-source input */
function tainted(): string { return 'attacker'; }

interface HtmlWriter
{
    /**
     * @psalm-taint-sink html $label
     *
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
     *
     * @psalm-impure
     */
    public function report(string $path = '', string $label = ''): void;
}

class OtherVariadicWriter
{
    /** @psalm-impure */
    final public function report(string $path = '', string ...$label): void {}
}

/**
 * `Union::isSingle()` counts union members, and an intersection is ONE member whose primary atomic
 * carries the rest in `extra_types`. So `getSingleAtomic()` silently answers with one component and
 * the handler would resolve that component's parameters alone: here the variadic one proves a
 * capture, the strip fires on the shared argument node, and the OTHER component's correctly
 * attributed `html` sink is erased with it. The variadic component is a CONCRETE class with a
 * final method, so neither the abstract/interface decline nor the exact-dispatch gate hides the
 * intersection guard: removing the `extra_types` check turns this fixture red. `resolveReceiverClass()` therefore declines on a
 * nonempty `extra_types` — an intersection is not "exactly one known class".
 *
 * Both orders are pinned because which component becomes the primary atomic is Psalm's choice, not
 * the docblock's, and a fix that only inspected the primary would pass one order and fail the other.
 * A separate sink method per order so neither can mask the other.
 */
function variadicComponentFirstReports(VariadicWriter&HtmlWriter $writer): void
{
    $writer->write(label: tainted());
}

function htmlComponentFirstReports(OtherHtmlWriter&OtherVariadicWriter $writer): void
{
    $writer->report(label: tainted());
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
TaintedHtml on line %d: Detected tainted HTML
