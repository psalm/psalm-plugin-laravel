--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Encrypter::encryptString() should escape user_secret taint — encrypted data
 * is no longer a plaintext secret that can be leaked.
 *
 * Tests the class-level stub (not the global encrypt() helper).
 */
function storeEncryptedToken(\Illuminate\Http\Request $request, \Illuminate\Encryption\Encrypter $encrypter): void {
    /** @var string $token */
    $token = $request->input('api_token');

    $encrypted = $encrypter->encryptString($token);

    file_put_contents('/tmp/safe.txt', $encrypted);
}
?>
--EXPECTF--
