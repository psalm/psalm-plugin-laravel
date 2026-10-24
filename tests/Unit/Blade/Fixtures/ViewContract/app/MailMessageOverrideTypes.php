<?php

declare(strict_types=1);

namespace ViewContractFixture;

final class MailMessageOverrideTypes
{
    public function viaView(): CustomMailMessage
    {
        // CustomMailMessage overrides toArray(), but the Mailer sets `message` after data() ran.
        return (new CustomMailMessage())->view('mail-message-string');
    }

    public function viaMarkdown(): CustomMailMessage
    {
        return (new CustomMailMessage())->markdown('mail-message-string');
    }
}
