--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace TaintedNamedArgumentVariadicRespreadGenuineDestinationKnownLimitation;

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

/**
 * KNOWN LIMITATION, an accepted soundness gap (see the NamedArgumentTaintHandler docblock and
 * "Known limitation: named arguments" in docs/security.md).
 *
 * `page:` is captured by `run()`'s variadic and re-spread onto `handle()`, where it genuinely
 * reaches `$page`'s `html` sink. A true positive is expected here, but the handler strips the
 * value at the call site to keep the #1395 spread fan-out false positive
 * (`SafeNamedArgumentVariadicRespreadFileFilesReporterShape.phpt`) silent, and that strip kills
 * the whole source flow, genuine destination included. Fixing this means Psalm honoring string
 * keys when it maps an unpacked argument onto parameters (vimeo/psalm#12252).
 */
function forwarderGenuineDestinationIsMissed(): void
{
    run(page: tainted());
}
?>
--EXPECTF--
