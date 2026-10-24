<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Mail\Mailable;

final class MailableMarkdownMessage extends Mailable
{
    public function viaMarkdown(): self
    {
        // The text pass of a markdown Mailable wraps the message in `TextMessage`.
        return $this->markdown("message-object");
    }

    public function viaView(): self
    {
        return $this->view("message-object");
    }
}
