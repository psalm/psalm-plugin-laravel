--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Guards against operator-class typos in TimingUnsafeComparisonHandler: each
 * of the three non-strict-equal equality operators (==, !==, !=) must register
 * a timing-unsafe sink on secret-tainted operands. Combined into one file so
 * the EXPECTF asserts all three TaintedUserSecret reports surface.
 */
function compareWithLooseEqual(\Illuminate\Foundation\Auth\User $user, string $given): bool {
    $password = $user->getAuthPassword();
    return $password == $given;
}

function compareWithNotIdentical(\Illuminate\Foundation\Auth\User $user, string $given): bool {
    $password = $user->getAuthPassword();
    return $password !== $given;
}

function compareWithLooseNotEqual(\Illuminate\Foundation\Auth\User $user, string $given): bool {
    $password = $user->getAuthPassword();
    return $password != $given;
}
?>
--EXPECTF--
TaintedUserSecret on line %d: %a
TaintedUserSecret on line %d: %a
TaintedUserSecret on line %d: %a
