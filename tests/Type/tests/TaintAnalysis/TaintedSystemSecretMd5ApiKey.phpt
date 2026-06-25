--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
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
%ATaintedSystemSecret on line %d: Detected tainted system secret leaking%A
