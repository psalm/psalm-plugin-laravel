--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Boundary helper that taints its return value with BOTH `html` and
 * `html_url` simultaneously, the way a real user-input value (carrying
 * the broad `html` taint via the `input` alias) would behave once an
 * app-level Form Request accessor opts the same value into the URL
 * context with `@psalm-taint-source html_url`.
 *
 * @psalm-taint-source html
 * @psalm-taint-source html_url
 */
function untrustedAvatarUrl_DualSrc(): string {
    return (string) getenv('AVATAR_URL');
}

/**
 * Stub-style URL allowlister (empty body) so the escape mask is driven
 * purely by the docblock and Psalm cannot auto-infer flow from the body.
 * This is the shape that actually exercises the `@psalm-flow` contract:
 * with `@psalm-flow ($url) -> return` present, only the `html_url` taint
 * is dropped and the `html` taint propagates through to the return
 * value. Without `@psalm-flow`, the bare `@psalm-taint-escape html_url`
 * strips every taint kind (`html` included), `htmlAndUrlSink_DualSrc`
 * then sees no taint, and this test stops firing, which is the mutation
 * we want to catch.
 *
 * The empty body is intentional. A non-empty body that returns `$url`
 * directly lets Psalm infer param-to-return flow on its own and the
 * `@psalm-flow` line becomes cosmetic, defeating the regression test.
 *
 * @psalm-taint-escape html_url
 * @psalm-flow ($url) -> return
 */
function appSafeUrl_DualSrc(string $url): string {}

/**
 * Local sink that listens for BOTH kinds. Routed through a per-file sink
 * (not `MailMessage::action()`) to dodge `TaintFlowGraph` BFS dedupe
 * against the other `html_url` / `html` tests in this directory. See
 * docs/contributing/taint-analysis.md "Testing-time pitfall".
 *
 * @psalm-taint-sink html $val
 * @psalm-taint-sink html_url $val
 */
function htmlAndUrlSink_DualSrc(string $val): void {}

/**
 * Asserts the second half of the "cleansers are not interchangeable"
 * contract: the URL sanitizer drops `html_url` only and DOES NOT drop
 * `html`. Sourced as both kinds; after the sanitizer only `html` should
 * remain; the sink listens for both, so `TaintedHtml` must fire.
 */
function flowsThroughSanitizer(): void {
    htmlAndUrlSink_DualSrc(appSafeUrl_DualSrc(untrustedAvatarUrl_DualSrc()));
}
?>
--EXPECTF--
%ATaintedHtml%a: Detected tainted HTML%A
