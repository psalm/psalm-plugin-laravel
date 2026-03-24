--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Encrypter::decryptString() should unescape user_secret taint — decrypted data
 * is back to plaintext and must be treated as tainted again.
 *
 * Tests the class-level stub (not the global decrypt() helper).
 */
function renderDecryptedToken(\Illuminate\Http\Request $request, \Illuminate\Encryption\Encrypter $encrypter): void {
    /** @var string $token */
    $token = $request->input('api_token');

    $encrypted = $encrypter->encryptString($token);
    $decrypted = $encrypter->decryptString($encrypted);

    echo $decrypted;
}
?>
--EXPECTF--
%ATaintedUserSecret on line %d: Detected tainted user secret leaking
%ATaintedSystemSecret on line %d: Detected tainted system secret leaking
%ATaintedHtml on line %d: Detected tainted HTML
%ATaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
%ATaintedUserSecret on line %d: Detected tainted user secret leaking
%ATaintedSystemSecret on line %d: Detected tainted system secret leaking
