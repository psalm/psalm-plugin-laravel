--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * hashPasswordForCookie() HMACs the password hash, removing user_secret taint.
 * Writing the HMAC digest to a file should not trigger TaintedUserSecret.
 */
function storeHashedPasswordCookie(\Illuminate\Foundation\Auth\User $user, \Illuminate\Auth\SessionGuard $guard): void {
    $hash = $user->getAuthPassword();

    $hmac = $guard->hashPasswordForCookie($hash);

    file_put_contents('/tmp/cookie.txt', $hmac);
}
?>
--EXPECTF--
