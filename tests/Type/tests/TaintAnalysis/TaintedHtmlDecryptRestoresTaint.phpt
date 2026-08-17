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
TaintedHtml on line %d: Detected tainted HTML
TaintedSystemSecret on line %d: Detected tainted system secret leaking
TaintedSystemSecret on line %d: Detected tainted system secret leaking
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedUserSecret on line %d: Detected tainted user secret leaking
TaintedUserSecret on line %d: Detected tainted user secret leaking
