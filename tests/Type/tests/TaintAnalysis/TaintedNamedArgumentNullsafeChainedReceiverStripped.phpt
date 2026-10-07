--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace TaintedNamedArgumentNullsafeChainedReceiverStripped;

/** @psalm-taint-source input */
function tainted(): string { return 'attacker'; }

final class Action
{
    /** @psalm-impure */
    public function run(string $a = '', string ...$rest): void
    {
        echo $a;
    }
}

function maybeAction(bool $present): ?Action
{
    return $present ? new Action() : null;
}

/**
 * Documents a deliberate asymmetry: a chained receiver is not a `$variable`, so a plain call on it
 * is not resolved (see TaintedNamedArgumentChainedReceiverVariadicRespreadKnownLimitation.phpt),
 * but Psalm rewrites a nullsafe call onto a virtual variable that holds the receiver's type, so the
 * handler resolves it and strips the variadic-captured `zzz:` value. `$a` is never reached by it,
 * so the html report that plain Psalm gives (vimeo/psalm#12251) is gone.
 */
function nullsafeChainedReceiverIsResolved(bool $present): void
{
    maybeAction($present)?->run(zzz: tainted());
}
?>
--EXPECTF--
