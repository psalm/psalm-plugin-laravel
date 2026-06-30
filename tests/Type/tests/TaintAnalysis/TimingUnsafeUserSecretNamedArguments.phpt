--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Named arguments may place the non-string parameters first, pushing the secret
 * comparand to a later syntactic position: strncmp(length: ..., string1: ...,
 * string2: $secret) puts the secret at args[2]. The handler resolves operands by
 * parameter name (then position), so the sink still registers. Both watched
 * functions whose comparands are not args[0]/args[1] under reordering are covered.
 */
function compareWithNamedStrncmp(\Illuminate\Foundation\Auth\User $user, string $candidate): bool {
    $password = $user->getAuthPassword();
    return strncmp(length: 8, string1: $candidate, string2: $password) === 0;
}

function compareWithNamedSubstrCompare(\Illuminate\Foundation\Auth\User $user, string $candidate): bool {
    $password = $user->getAuthPassword();
    return substr_compare(offset: 0, haystack: $candidate, needle: $password) === 0;
}
?>
--EXPECTF--
TaintedUserSecret on line %d: %a
TaintedUserSecret on line %d: %a
