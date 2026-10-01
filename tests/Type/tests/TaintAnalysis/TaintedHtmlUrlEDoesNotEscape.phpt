--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/** @psalm-taint-source html_url */
function untrustedAvatarUrl_EOnly(): string {
    return (string) getenv('AVATAR_URL');
}

/**
 * Per-file stand-in for the stubbed `MailMessage::action()` sink, so this test exercises the
 * `e()` escape path independently of other fixtures reaching the shared sink (see "Testing-time
 * pitfall" in docs/contributing/taint-analysis.md).
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
TaintedCustom on line %d: Detected tainted html_url
