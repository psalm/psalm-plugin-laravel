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
TaintedSystemSecret on line %d: Detected tainted system secret leaking
TaintedSystemSecret on line %d: Detected tainted system secret leaking
TaintedUserSecret on line %d: Detected tainted user secret leaking
TaintedUserSecret on line %d: Detected tainted user secret leaking
