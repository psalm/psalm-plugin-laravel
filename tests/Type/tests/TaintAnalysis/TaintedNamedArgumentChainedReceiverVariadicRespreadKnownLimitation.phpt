--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace TaintedNamedArgumentChainedReceiverVariadicRespreadKnownLimitation;

/** @psalm-taint-source input */
function tainted(): string { return 'attacker'; }

final class PropertyAction
{
    public function run(string ...$arguments): void
    {
        $this->handle(...$arguments);
    }

    public function handle(string $directory = '', string $page = ''): void
    {
        system($directory);
        echo \count([$page]);
    }
}

final class ChainedAction
{
    public static function make(): self
    {
        return new self();
    }

    public function run(string ...$arguments): void
    {
        $this->handle(...$arguments);
    }

    public function handle(string $directory = '', string $page = ''): void
    {
        passthru($directory);
        echo \count([$page]);
    }
}

final class Controller
{
    public function __construct(private PropertyAction $action) {}

    /**
     * KNOWN LIMITATION: the #1395 spread false positive stays visible when the receiver is not a
     * plain `$variable` of one known class (a property fetch or a chained call), because the
     * handler cannot resolve the callee there and leaves the argument to Psalm. The shell reports
     * on `handle()`'s `$directory` below are that false positive: `page:` only ever reaches
     * `$page`. Plain Psalm reports the same.
     */
    public function viaProperty(): void
    {
        $this->action->run(page: tainted());
    }

    public function viaChain(): void
    {
        ChainedAction::make()->run(page: tainted());
    }
}
?>
--EXPECTF--
TaintedShell on line %d: Detected tainted shell code
TaintedShell on line %d: Detected tainted shell code
