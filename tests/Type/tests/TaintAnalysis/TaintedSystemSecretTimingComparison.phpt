--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Comparing a system secret with === is vulnerable to timing attacks.
 * Use hash_equals() for constant-time comparison.
 *
 * @psalm-taint-source system_secret
 */
function getExpectedToken(): string {
    return 'secret-token';
}

function verifyToken(string $userInput): bool {
    return $userInput === getExpectedToken();
}
?>
--EXPECTF--
%ATaintedSystemSecret on line %d: Detected tainted system secret leaking
