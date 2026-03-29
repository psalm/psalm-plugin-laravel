--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * bcrypt() delegates to HashManager::make() — the original system secret
 * cannot be recovered from the hash. Should not trigger TaintedSystemSecret.
 */
function storeHashedToken(): void {
    /** @psalm-taint-source system_secret */
    $token = getenv('SECRET_TOKEN');

    $hashed = bcrypt((string) $token);

    file_put_contents('/tmp/safe.txt', $hashed);
}
?>
--EXPECTF--
