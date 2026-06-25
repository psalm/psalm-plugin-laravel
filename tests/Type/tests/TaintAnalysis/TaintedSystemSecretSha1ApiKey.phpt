--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * sha1() is a broken hash (CWE-328). A system_secret source flowing into
 * sha1() must trigger TaintedSystemSecret — sha1 cannot be used to mask
 * server credentials.
 *
 * @psalm-taint-source system_secret
 */
function readApiKeyFromEnv(): string {
    return (string) getenv('API_KEY');
}

function legacySha1ApiKey(): string {
    return sha1(readApiKeyFromEnv());
}
?>
--EXPECTF--
%ATaintedSystemSecret on line %d: Detected tainted system secret leaking%A
