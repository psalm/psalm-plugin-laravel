--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

/**
 * Cross-coverage of the (kind, sink) matrix: system_secret flows into md5()
 * (complements TaintedSystemSecretSha1ApiKey and TaintedUserSecretMd5Password).
 *
 * @psalm-taint-source system_secret
 */
function readSystemApiKey(): string {
    return (string) getenv('API_KEY');
}

function legacyMd5ApiKey(): string {
    return md5(readSystemApiKey());
}
?>
--EXPECTF--
WeakHash on line %d: md5() hashes with a weak algorithm: use hash() with a SHA-2 or SHA-3 one, password_hash() for passwords, or xxh3 where only speed matters
TaintedSystemSecret on line %d: Detected tainted system secret leaking
