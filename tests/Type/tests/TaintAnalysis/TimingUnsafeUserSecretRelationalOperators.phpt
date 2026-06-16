--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * The relational operators (<, <=, >, >=) compare strings lexicographically
 * byte-by-byte, leaking the secret's ordering exactly like <=> and strcmp().
 * Each of the four must register a timing-unsafe sink on secret-tainted operands.
 * Combined into one file so the EXPECTF asserts all four TaintedUserSecret reports.
 */
function compareWithSmaller(\Illuminate\Foundation\Auth\User $user, string $given): bool {
    $password = $user->getAuthPassword();
    return $password < $given;
}

function compareWithSmallerOrEqual(\Illuminate\Foundation\Auth\User $user, string $given): bool {
    $password = $user->getAuthPassword();
    return $password <= $given;
}

function compareWithGreater(\Illuminate\Foundation\Auth\User $user, string $given): bool {
    $password = $user->getAuthPassword();
    return $password > $given;
}

function compareWithGreaterOrEqual(\Illuminate\Foundation\Auth\User $user, string $given): bool {
    $password = $user->getAuthPassword();
    return $password >= $given;
}
?>
--EXPECTF--
TaintedUserSecret on line %d: %a
TaintedUserSecret on line %d: %a
TaintedUserSecret on line %d: %a
TaintedUserSecret on line %d: %a
