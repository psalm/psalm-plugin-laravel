<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Notifications\Messages\MailMessage;

final class MailMessageMessagePassed
{
    public function viaView(): MailMessage
    {
        return (new MailMessage())->view('mail-message-object', ['message' => 'plain']);
    }

    public function viaMarkdown(): MailMessage
    {
        return (new MailMessage())->markdown('mail-message-object', ['message' => 'plain']);
    }
}
