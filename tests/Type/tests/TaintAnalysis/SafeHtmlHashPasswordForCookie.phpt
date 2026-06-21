--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * hashPasswordForCookie() returns a hex HMAC digest, which cannot carry html (or any other
 * injection) taint, so echoing the result must NOT report TaintedHtml.
 *
 * The previous full-class SessionGuard stub re-propagated non-secret taints through the HMAC
 * via @psalm-flow; that was over-conservative (a hex digest is injection-safe) and is dropped
 * now that the taint lives in GuardTaintHandler. The user_secret escape is kept (see
 * SafeAuthHashPasswordForCookie.phpt).
 */
function hashSanitizesHtmlTaint(\Illuminate\Http\Request $request, \Illuminate\Auth\SessionGuard $guard): void {
    /** @var string $input */
    $input = $request->input('data');

    $hashed = $guard->hashPasswordForCookie($input);

    echo $hashed;
}
?>
--EXPECTF--
