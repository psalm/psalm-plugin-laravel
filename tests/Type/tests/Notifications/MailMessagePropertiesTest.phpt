--FILE--
<?php declare(strict_types=1);

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\HtmlString;

// Shapes follow what MailMessage's own setters write: from() stores [address, name],
// replyTo() appends [address, name] pairs, attach() stores a resolved path, attachData()
// stores raw data. $view is null until view()/text() runs and markdown() resets it.

function mail_message_properties(MailMessage $mail): void
{
    /** @psalm-check-type-exact $view = array<array-key, mixed>|null|string */
    $view = $mail->view;

    /** @psalm-check-type-exact $from = array{0?: string, 1?: null|string} */
    $from = $mail->from;

    /** @psalm-check-type-exact $replyTo = list<array{0: string, 1?: null|string}> */
    $replyTo = $mail->replyTo;

    /** @psalm-check-type-exact $attachments = list<array{file: string, options: array<array-key, mixed>}> */
    $attachments = $mail->attachments;

    /** @psalm-check-type-exact $rawAttachments = list<array{data: resource|string, name: string, options: array<array-key, mixed>}> */
    $rawAttachments = $mail->rawAttachments;

    echo \count([$view, $from, $replyTo, $attachments, $rawAttachments]);
}

// The name is optional in direct assignments, MailChannel reads it with Arr::get().
function mail_message_sender_without_name(MailMessage $mail): void
{
    $mail->from = ['noreply@example.com'];
    $mail->replyTo = [['support@example.com']];
}

// The view() branch of render() returns Mailer::render(), a plain string.
function rendered(MailMessage $mail): HtmlString|string
{
    /** @psalm-check-type-exact $html = HtmlString|string */
    $html = $mail->render();

    return $html;
}
?>
--EXPECTF--
