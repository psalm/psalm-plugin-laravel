--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace SafeNamedArgumentVariadicCaptureReceiverAndConstructorStripped;

/** @psalm-taint-source input */
function tainted(): string { return 'attacker'; }

final class Mailer
{
    /** @psalm-taint-sink file $path */
    public function write(string $path = 'safe', mixed ...$extra): void
    {
        echo $path;
    }
}

final class Job
{
    /** @psalm-taint-sink file $path */
    public function __construct(string $path = 'safe', mixed ...$extra)
    {
        echo $path;
    }
}

/**
 * The variadic-capture strip can only fire when the callee's declared parameters resolve, so
 * these two shapes are the negative coverage for `resolveReceiverClass()` (a `MethodCall` on a
 * receiver typed as exactly one class) and `resolveClassNamePart()` (a `New_` constructor).
 * Break either resolver and the mis-attributed `TaintedFile` against `$path` comes back.
 */
function resolvedReceiverVariadicCaptureIsStripped(Mailer $mailer): void
{
    $mailer->write(zzz: tainted());
}

function constructorVariadicCaptureIsStripped(): void
{
    new Job(zzz: tainted());
}
?>
--EXPECTF--
