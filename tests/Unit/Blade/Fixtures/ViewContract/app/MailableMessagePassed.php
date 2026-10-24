<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Mail\Mailable;

final class MailableMessagePassed extends Mailable
{
    public function viaView(): self
    {
        // Mailer::send() replaces the passed `message` with the Message object.
        return $this->view('mail-message-object', ['message' => 'plain']);
    }

    public function viaMarkdown(): self
    {
        // The markdown renderer keeps the passed value, so the template is checked against it.
        return $this->markdown('mail-message-object', ['message' => 'plain']);
    }
}
