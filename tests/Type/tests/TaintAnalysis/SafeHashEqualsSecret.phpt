--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * hash_equals() provides constant-time comparison — no timing attack.
 *
 * @psalm-taint-source system_secret
 */
function getExpectedToken(): string {
    return 'secret-token';
}

function verifyToken(string $userInput): bool {
    return hash_equals(getExpectedToken(), $userInput);
}
?>
--EXPECTF--

