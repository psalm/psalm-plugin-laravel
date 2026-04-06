--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * decrypt() should unescape user_secret taint — decrypted data is back to
 * plaintext and must be treated as tainted again.
 *
 * @psalm-suppress MixedAssignment, MixedArgument
 */
function renderDecryptedInput(\Illuminate\Http\Request $request): void {
    /** @var string $encryptedComment */
    $encryptedComment = $request->input('encrypted_comment');

    $encrypted = encrypt($encryptedComment);
    $decrypted = decrypt($encrypted);

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
