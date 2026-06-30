--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Encrypter::decryptString() unescapes system_secret, so its return value
 * carries system_secret taint. Comparing it with strcasecmp() is
 * timing-unsafe and must trigger TaintedSystemSecret.
 */
function compareDecryptedSecretWithStrcasecmp(\Illuminate\Encryption\Encrypter $encrypter, string $payload, string $candidate): bool {
    $secret = $encrypter->decryptString($payload);
    return strcasecmp($secret, $candidate) === 0;
}
?>
--EXPECTF--
%ATaintedSystemSecret%A
