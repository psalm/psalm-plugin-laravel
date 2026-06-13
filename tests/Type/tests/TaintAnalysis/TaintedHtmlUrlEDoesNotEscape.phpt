--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/** @psalm-taint-source html_url */
function untrustedAvatarUrl_EOnly(): string {
    return (string) getenv('AVATAR_URL');
}

/**
 * Local stand-in for a real `html_url` sink, used here instead of the
 * stubbed `MailMessage::action()` to avoid Psalm's per-sink-node taint
 * de-duplication: the BFS in `TaintFlowGraph::connectSinksAndSources()`
 * emits at most one error per (sink-node, taint-mask) pair across the
 * whole codebase. With a single shared sink, only one of the parallel
 * `Tainted<Path>` tests would fire (whichever the BFS reaches first).
 * The custom sink here is a per-file node, so this test exercises the
 * `e()` escape path independently from `TaintedHtmlUrlMailActionWithoutSanitize.phpt`.
 *
 * @psalm-taint-sink html_url $url
 */
function urlSink_EOnly(string $url): void { echo $url; }

/**
 * The HTML escape cleanser (`e()`) and the URL-context cleanser are not
 * interchangeable. `e()` only escapes the `html` and `has_quotes` taint
 * kinds; it does NOT validate the URL scheme. A `javascript:` URL passes
 * through `e()` unchanged.
 *
 * A value tainted as `html_url` and run through `e()` only must therefore
 * still be flagged when it reaches an `html_url` sink. This is the proof
 * that the two cleansers cover different attack surfaces and that the
 * Filament-style stored-XSS (GHSA-3fc8-8hp6-6jr4) is detectable by the
 * plugin once the value is marked at the boundary.
 */
function sendActionWithEEscapedUrl(): void {
    urlSink_EOnly(e(untrustedAvatarUrl_EOnly()));
}
?>
--EXPECTF--
%ATaintedCustom%a: Detected tainted html_url%A
