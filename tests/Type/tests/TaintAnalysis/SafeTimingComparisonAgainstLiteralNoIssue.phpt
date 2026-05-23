--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Comparing a secret against a literal scalar (null, '', 'string', 42, false)
 * carries no information about the secret's content — the literal IS the
 * known half. The handler must skip these defensive shapes to avoid
 * false-positive TaintedUserSecret on idiomatic null/empty checks.
 */
function rememberTokenIsNull(\Illuminate\Foundation\Auth\User $user): bool {
    $token = $user->getRememberToken();
    return $token === null;
}

function passwordIsEmpty(\Illuminate\Foundation\Auth\User $user): bool {
    $password = $user->getAuthPassword();
    return $password === '';
}

function passwordIsNotSentinel(\Illuminate\Foundation\Auth\User $user): bool {
    $password = $user->getAuthPassword();
    return $password !== 'unset';
}

function rememberTokenIsFalsy(\Illuminate\Foundation\Auth\User $user): bool {
    $token = $user->getRememberToken();
    return $token != false;
}

function passwordEqualsMagicConst(\Illuminate\Foundation\Auth\User $user): bool {
    $password = $user->getAuthPassword();
    return $password === __FILE__;
}

function passwordEqualsLiteralConcat(\Illuminate\Foundation\Auth\User $user): bool {
    $password = $user->getAuthPassword();
    return $password === 'sentinel-' . 'marker';
}
?>
--EXPECTF--
