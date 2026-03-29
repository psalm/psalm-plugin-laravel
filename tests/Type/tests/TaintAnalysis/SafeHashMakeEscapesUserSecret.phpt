--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * HashManager::make() is a one-way hash — the original plaintext password
 * cannot be recovered. Writing the hash should not trigger TaintedUserSecret.
 */
function storeHashedPassword(\Illuminate\Foundation\Auth\User $user, \Illuminate\Hashing\HashManager $hash): void {
    $password = $user->getAuthPassword();

    $hashed = $hash->make($password);

    file_put_contents('/tmp/safe.txt', $hashed);
}
?>
--EXPECTF--
