--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Indirect flow: secret is assigned to a variable before comparison.
 * The taint should still propagate through the variable.
 *
 * @psalm-taint-source system_secret
 */
function getApiKey(): string {
    return 'secret-api-key';
}

function verifyApiKey(string $input): bool {
    $expected = getApiKey();
    return $input === $expected;
}
?>
--EXPECTF--
%ATaintedSystemSecret on line %d: Detected tainted system secret leaking
