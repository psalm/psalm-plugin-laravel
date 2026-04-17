--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * !== has the same timing characteristics as === — the negation happens
 * after the byte-by-byte comparison, so it leaks the same timing info.
 *
 * @psalm-taint-source system_secret
 */
function getExpectedToken(): string {
    return 'secret-token';
}

function rejectInvalidToken(string $userInput): void {
    if ($userInput !== getExpectedToken()) {
        throw new \RuntimeException('Invalid token');
    }
}
?>
--EXPECTF--
%ATaintedSystemSecret on line %d: Detected tainted system secret leaking
