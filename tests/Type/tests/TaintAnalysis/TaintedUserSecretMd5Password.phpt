--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
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
%ATaintedUserSecret on line %d: Detected tainted user secret leaking%A
