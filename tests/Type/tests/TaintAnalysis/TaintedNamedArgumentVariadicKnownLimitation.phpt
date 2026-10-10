--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace TaintedNamedArgumentVariadicKnownLimitation;

/** @psalm-taint-source input */
function tainted(): string { return 'attacker'; }

/**
 * @psalm-taint-sink file $directory
 * @psalm-taint-sink html $page
 */
function handle(?string $directory = null, string $page = ''): void
{
    echo (string) $directory;
    echo $page;
}

function run(string ...$arguments): void
{
    handle(...$arguments);
}

function sinkAtOffsetZero(string ...$rest): void
{
    foreach ($rest as $chunk) {
        system($chunk);
    }
}

function sinkAfterOne(string $first = '', string ...$rest): void
{
    foreach ($rest as $chunk) {
        passthru($chunk);
    }
}

function sinkNamedByVariadic(string ...$rest): void
{
    foreach ($rest as $chunk) {
        exec($chunk);
    }
}

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

/**
 * KNOWN, retained false positive (plain Psalm too, the two shell reports below): a property or
 * chained receiver is not resolved, so the #1395 spread fan-out still reports on `handle()`.
 */
final class Controller
{
    public function __construct(private PropertyAction $action) {}

    public function viaProperty(): void
    {
        $this->action->run(page: tainted());
    }

    public function viaChain(): void
    {
        ChainedAction::make()->run(page: tainted());
    }
}

/**
 * ACCEPTED LOSS (silent, plain Psalm reports each): the strip drops the whole flow, so a genuine
 * sink behind the re-spread (`$page`'s `html` sink) or in the variadic's own body (`zzz:` or the
 * variadic's own name, at and after the variadic's offset) is missed. See decisions.md.
 */
function genuineSinksAreMissed(): void
{
    run(page: tainted());
    sinkAtOffsetZero(zzz: tainted());
    sinkAfterOne('x', zzz: tainted());
    sinkNamedByVariadic(rest: tainted());
}
?>
--EXPECTF--
TaintedShell on line %d: Detected tainted shell code
TaintedShell on line %d: Detected tainted shell code
