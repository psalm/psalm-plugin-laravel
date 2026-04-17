--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Comparing a user secret with == is vulnerable to timing attacks.
 * Use hash_equals() for constant-time comparison.
 *
 * @psalm-taint-source user_secret
 */
function getUserPassword(): string {
    return 'password';
}

function verifyPassword(string $input): bool {
    return $input == getUserPassword();
}
?>
--EXPECTF--
%ATaintedUserSecret on line %d: Detected tainted user secret leaking
