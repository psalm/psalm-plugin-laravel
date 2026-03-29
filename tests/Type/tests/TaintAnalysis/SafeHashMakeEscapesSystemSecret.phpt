--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * HashManager::make() is a one-way hash — the original system secret
 * cannot be recovered. Writing the hash should not trigger TaintedSystemSecret.
 */
function storeHashedApiKey(\Illuminate\Hashing\HashManager $hash): void {
    /** @psalm-taint-source system_secret */
    $apiKey = getenv('API_KEY');

    $hashed = $hash->make((string) $apiKey);

    file_put_contents('/tmp/safe.txt', $hashed);
}
?>
--EXPECTF--
