--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
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
WeakHash on line %d: sha1() hashes with a weak algorithm: use hash() with a SHA-2 or SHA-3 one, password_hash() for passwords, or xxh3 where only speed matters
TaintedSystemSecret on line %d: Detected tainted system secret leaking
