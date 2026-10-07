--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace TaintedNamedArgumentAbstractVariadicOverrideReports;

/** @psalm-taint-source input */
function tainted(): string { return 'attacker'; }

abstract class AbstractHandler
{
    /** @psalm-impure */
    abstract public function go(string ...$args): void;
}

final class ConcreteHandler extends AbstractHandler
{
    /** @psalm-suppress ParamNameMismatch */
    #[\Override]
    public function go(string $sink = ''): void
    {
        system($sink);
    }
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
    public function dispatch(string $sink = ''): void
    {
        passthru($sink);
    }
}

/**
 * The receiver is typed as the abstract class, whose `go()` declares a variadic, so the name
 * `sink:` binds to it at the call site. An abstract method has no body that could re-spread the
 * argument, so there is no spread false positive to silence, and the genuine override
 * (`ConcreteHandler::go($sink)`) reaches `system()`. The handler must preserve the argument.
 */
function abstractReceiverReports(AbstractHandler $handler): void
{
    $handler->go(sink: tainted());
}

/** Same shape through an interface method, which is abstract by definition. */
function interfaceReceiverReports(HandlerContract $contract): void
{
    $contract->dispatch(sink: tainted());
}
?>
--EXPECTF--
TaintedShell on line %d: Detected tainted shell code
TaintedShell on line %d: Detected tainted shell code
