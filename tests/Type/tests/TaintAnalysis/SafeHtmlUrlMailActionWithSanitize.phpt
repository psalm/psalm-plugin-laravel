--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/** @psalm-taint-source html_url */
function untrustedAvatarUrl_Safe(): string {
    return (string) getenv('AVATAR_URL');
}

/**
 * App-defined URL allowlister. Pairs `@psalm-taint-escape html_url` with
 * `@psalm-flow ($url) -> return` so only the `html_url` taint is dropped
 * and any other taint kinds the value carries continue to flow. See
 * docs/contributing/taint-analysis.md "URL context vs HTML escaping" for
 * why the `@psalm-flow` pairing is mandatory.
 *
 * @psalm-taint-escape html_url
 * @psalm-flow ($url) -> return
 */
function appSafeUrl_Safe(string $url): string {
    return preg_match('#^(https?|mailto|tel):#i', $url) === 1 ? $url : '#';
}

/**
 * A URL-tainted value run through the sanitizer becomes clean for the
 * `html_url` taint and the same call to `MailMessage::action()` no longer
 * triggers TaintedCustom.
 */
function sendActionWithSanitizedUrl(\Illuminate\Notifications\Messages\MailMessage $mail): void {
    $mail->action('View profile', appSafeUrl_Safe(untrustedAvatarUrl_Safe()));
}
?>
--EXPECTF--
