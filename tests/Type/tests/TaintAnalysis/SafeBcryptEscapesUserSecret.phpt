--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * bcrypt() is a one-way hash — the original plaintext password
 * cannot be recovered. Writing the hash should not trigger TaintedUserSecret.
 */
function storeBcryptedPassword(\Illuminate\Foundation\Auth\User $user): void {
    $password = $user->getAuthPassword();

    $hashed = bcrypt($password);

    file_put_contents('/tmp/safe.txt', $hashed);
}
?>
--EXPECTF--
