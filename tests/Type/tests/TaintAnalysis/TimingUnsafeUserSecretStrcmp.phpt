--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * strcmp() compares character-by-character — its return value reveals
 * partial ordering of the secret, so it is timing-unsafe even when the
 * caller only checks the === 0 result.
 */
function compareRememberTokenWithStrcmp(\Illuminate\Foundation\Auth\User $user, string $candidate): bool {
    $token = (string) $user->getRememberToken();
    return strcmp($token, $candidate) === 0;
}
?>
--EXPECTF--
%ATaintedUserSecret%A
