--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * system_secret must trip the operator path too, not only the string-compare
 * functions. Encrypter::decryptString() unescapes system_secret, so comparing
 * its result with === is timing-unsafe and must trigger TaintedSystemSecret.
 */
function compareDecryptedSecretWithIdentical(\Illuminate\Encryption\Encrypter $encrypter, string $payload, string $candidate): bool {
    $secret = $encrypter->decryptString($payload);
    return $secret === $candidate;
}
?>
--EXPECTF--
%ATaintedSystemSecret%A
