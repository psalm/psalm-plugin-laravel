--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * strcmp() is also timing-unsafe — it compares character-by-character.
 * Use hash_equals() instead.
 *
 * @psalm-taint-source system_secret
 */
function getApiKey(): string {
    return 'secret-api-key';
}

function verifyApiKey(string $input): bool {
    return strcmp($input, getApiKey()) === 0;
}
?>
--EXPECTF--
%ATaintedSystemSecret on line %d: Detected tainted system secret leaking
