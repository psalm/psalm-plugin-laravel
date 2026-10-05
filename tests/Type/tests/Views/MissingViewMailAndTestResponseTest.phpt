--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm-with-optin-custom-issues.xml
--FILE--
<?php declare(strict_types=1);

// MailMessage::view()/markdown() — both take a single view name at position 0.
function _diMailMessage(\Illuminate\Notifications\Messages\MailMessage $mailMessage): void {
    $mailMessage->view('mail-view-missing');
    $mailMessage->view('welcome');
    $mailMessage->markdown('mail-markdown-missing');
    $mailMessage->markdown('welcome');
}

// text() renders its argument as a view; the array form of view() mirrors Mailer::parseView().
function _diMailMessageTextAndArray(\Illuminate\Notifications\Messages\MailMessage $mailMessage): void {
    $mailMessage->text('mail-text-missing');
    $mailMessage->text('welcome');
    $mailMessage->view(['html' => 'mail-html-missing', 'text' => 'mail-text-plain-missing']);
    $mailMessage->view(['html' => 'welcome', 'text' => 'welcome']);
    $mailMessage->view(['welcome', 'mail-list-plain-missing']);
    // key 0 wins: 'html' is not read
    $mailMessage->view([0 => 'welcome', 'html' => 'mail-ignored-html-missing']);
    // 'raw' is raw text, not a view name
    $mailMessage->view(['raw' => 'mail-raw-not-a-view']);
}

// parseView() branches on isset($view[0]); the handler only follows a branch it can prove.
function _diMailMessageKeyZeroPrecedence(
    \Illuminate\Notifications\Messages\MailMessage $mailMessage,
    ?string $maybeNull,
    string $nonNull,
    array $parts,
    int|string $key,
): void {
    // possibly-null key 0: either branch is a guess, so neither key 1 nor 'html' is reported
    $mailMessage->view([$maybeNull, 'mail-nullable-key1-missing', 'html' => 'mail-nullable-html-missing']);
    // proven non-null key 0: the key-0 branch is taken, 'html' is ignored
    $mailMessage->view([$nonNull, 'mail-nonnull-key1-missing', 'html' => 'mail-nonnull-html-ignored']);
    // literal null at key 0 is not "set": the named keys are used, key 1 is ignored
    $mailMessage->view([null, 'mail-null-key1-ignored', 'html' => 'welcome']);
    // a spread hides the keys
    $mailMessage->view([...$parts, 'mail-spread-missing']);
    // a non-literal key may be 0
    $mailMessage->view([$key => 'welcome', 'html' => 'mail-nonliteral-key-html-missing']);
    // '0' is the integer key 0, so the implicit next key is 1
    $mailMessage->view(['0' => 'mail-strkey-zero-missing', 'welcome']);
}

/**
 * @template T
 * @param T $value
 */
function _diMailMessageTemplateKeyZero(\Illuminate\Notifications\Messages\MailMessage $mailMessage, mixed $value): void {
    // an unbounded template may be null: the branch is unknown, so nothing is reported
    $mailMessage->view([$value, 'mail-template-key1-missing', 'html' => 'welcome']);
}

function _diMailMessageNegativeKey(\Illuminate\Notifications\Messages\MailMessage $mailMessage): void {
    // the implicit index after a negative key differs between PHP 8.2 and 8.3+, so the array is skipped
    $mailMessage->view(['-2' => 'welcome', 'mail-negative-key-implicit-missing']);
}

function _diMailMessageNamedArgs(\Illuminate\Notifications\Messages\MailMessage $mailMessage): void {
    $mailMessage->text(textView: 'mail-named-text-missing');
    $mailMessage->view(view: ['html' => 'mail-named-view-html-missing']);
}

// TestResponse::assertViewIs() compares $value against the rendered view's name.
function _diTestResponse(\Illuminate\Testing\TestResponse $testResponse): void {
    $testResponse->assertViewIs('assert-view-is-missing');
    $testResponse->assertViewIs('welcome');
}
?>
--EXPECTF--
MissingView on line %d: View 'mail-view-missing' not found in any of the registered view paths
MissingView on line %d: View 'mail-markdown-missing' not found in any of the registered view paths
MissingView on line %d: View 'mail-text-missing' not found in any of the registered view paths
MissingView on line %d: View 'mail-html-missing' not found in any of the registered view paths
MissingView on line %d: View 'mail-text-plain-missing' not found in any of the registered view paths
MissingView on line %d: View 'mail-list-plain-missing' not found in any of the registered view paths
MissingView on line %d: View 'mail-nonnull-key1-missing' not found in any of the registered view paths
MissingView on line %d: View 'mail-strkey-zero-missing' not found in any of the registered view paths
MissingView on line %d: View 'mail-named-text-missing' not found in any of the registered view paths
MissingView on line %d: View 'mail-named-view-html-missing' not found in any of the registered view paths
MissingView on line %d: View 'assert-view-is-missing' not found in any of the registered view paths
