<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Notifications\Messages\MailMessage;

final class MailMessageMarkdownMessage
{
    public function build(): MailMessage
    {
        // MailChannel renders the markdown message once, with the plain `Message`.
        return (new MailMessage())->markdown('mail-message-object');
    }
}
