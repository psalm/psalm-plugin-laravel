--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * encrypt() should escape user_secret taint — encrypted data is no longer
 * a plaintext secret that can be leaked.
 */
function storeEncryptedPassword(\Illuminate\Http\Request $request): void {
    /** @var string $password */
    $password = $request->input('password');

    $encrypted = encrypt($password);

    // Storing encrypted value should not trigger TaintedInput
    file_put_contents('/tmp/safe.txt', $encrypted);
}
?>
--EXPECTF--
