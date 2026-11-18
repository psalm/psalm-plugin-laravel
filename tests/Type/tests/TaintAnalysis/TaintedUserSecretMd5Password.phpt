--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

/**
 * md5() is a broken hash (CWE-328). Passing a user_secret source
 * (Authenticatable::getAuthPassword()) into md5() must trigger
 * TaintedUserSecret because the digest is feasible to brute force.
 */
function legacyMd5Password(\Illuminate\Foundation\Auth\User $user): string {
    return md5($user->getAuthPassword());
}
?>
--EXPECTF--
WeakHash on line %d: md5() hashes with a weak algorithm: use hash() with a SHA-2 or SHA-3 one, password_hash() for passwords, or xxh3 where only speed matters
TaintedUserSecret on line %d: Detected tainted user secret leaking
