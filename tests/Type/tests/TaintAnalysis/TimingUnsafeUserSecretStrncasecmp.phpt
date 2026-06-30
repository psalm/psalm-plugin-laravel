--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * strncasecmp() is the case-insensitive prefix compare — still byte-by-byte
 * and timing-unsafe. Guards the strncasecmp arm of TIMING_UNSAFE_FUNCTIONS,
 * which strncmp's test does not cover.
 */
function comparePrefixCaseInsensitively(\Illuminate\Foundation\Auth\User $user, string $prefix): bool {
    $password = $user->getAuthPassword();
    return strncasecmp($password, $prefix, 8) === 0;
}
?>
--EXPECTF--
%ATaintedUserSecret%A
