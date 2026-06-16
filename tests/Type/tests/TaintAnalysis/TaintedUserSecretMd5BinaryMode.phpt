--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Binary-mode regression guard: the sink targets the `$string` parameter
 * by name. Calling md5($secret, true) for a 16-byte raw digest must still
 * fire, otherwise a contributor could silently break the sink by moving
 * the annotation off the named parameter.
 */
function legacyMd5PasswordBinary(\Illuminate\Foundation\Auth\User $user): string {
    return md5($user->getAuthPassword(), true);
}
?>
--EXPECTF--
%ATaintedUserSecret on line %d: Detected tainted user secret leaking%A
