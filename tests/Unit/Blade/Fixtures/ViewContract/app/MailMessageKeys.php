<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Notifications\Messages\MailMessage;

final class MailMessageKeys
{
    public function build(): MailMessage
    {
        // MailMessage::data() is toArray() + viewData, and Mailer adds `$message`.
        return (new MailMessage())->view("mail-message-keys");
    }

    public function overridden(): MailMessage
    {
        // viewData wins over the toArray() key of the same name, so `level` is the int passed here.
        return (new MailMessage())->markdown("mail-message-level", ["level" => 5]);
    }

    public function missing(): MailMessage
    {
        return (new MailMessage())->view("mail-message-absent");
    }

    public function markdownMessage(): MailMessage
    {
        // The markdown renderer merges the call's data over the Mailer's `$message`.
        return (new MailMessage())->markdown("message-string", ["message" => "text"]);
    }

    public function mistyped(): MailMessage
    {
        // The framework types these two keys: a string and an `Illuminate\Mail\Message`.
        return (new MailMessage())->view("mail-message-mistyped");
    }
}
