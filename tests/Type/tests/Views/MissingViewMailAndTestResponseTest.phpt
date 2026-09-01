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

// TestResponse::assertViewIs() compares $value against the rendered view's name.
function _diTestResponse(\Illuminate\Testing\TestResponse $testResponse): void {
    $testResponse->assertViewIs('assert-view-is-missing');
    $testResponse->assertViewIs('welcome');
}
?>
--EXPECTF--
MissingView on line %d: View 'mail-view-missing' not found in any of the registered view paths
MissingView on line %d: View 'mail-markdown-missing' not found in any of the registered view paths
MissingView on line %d: View 'assert-view-is-missing' not found in any of the registered view paths
