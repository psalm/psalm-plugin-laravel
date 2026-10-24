<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Mail\Mailable;

final class MailableMessageProperty extends Mailable
{
    public function __construct(public string $message) {}

    public function build(): self
    {
        // Mailer::send() sets `message` after the data merge, so the template gets the Message object.
        return $this->view('mail-message-string');
    }

    public function viaMarkdown(): self
    {
        // The markdown renderer merges the view data over the Mailer's data: the property wins.
        return $this->markdown('mail-message-string');
    }
}
