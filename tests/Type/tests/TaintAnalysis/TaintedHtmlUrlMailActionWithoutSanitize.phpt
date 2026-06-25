--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Boundary helper that marks its return value as a URL pulled from an
 * untrusted source. In a real app this would wrap `$request->input('url')`,
 * a Form Request accessor, an Eloquent attribute, etc. See
 * docs/contributing/taint-analysis.md ("URL context vs HTML escaping") for
 * why `html_url` is opt-in and not part of the generic `input` alias.
 *
 * @psalm-taint-source html_url
 */
function untrustedAvatarUrl_Without(): string {
    return (string) getenv('AVATAR_URL');
}

/**
 * A URL-tainted value emitted into MailMessage::action($url) without any
 * URL-sanitizer must trigger TaintedCustom for the `html_url` kind. The
 * `action()` parameter is interpolated into an `<a href="...">` attribute
 * server-side, so a `javascript:` / `data:` URL would execute (see
 * Filament GHSA-3fc8-8hp6-6jr4).
 */
function sendActionWithUntrustedUrl(\Illuminate\Notifications\Messages\MailMessage $mail): void {
    $mail->action('View profile', untrustedAvatarUrl_Without());
}
?>
--EXPECTF--
%ATaintedCustom%a: Detected tainted html_url%A
