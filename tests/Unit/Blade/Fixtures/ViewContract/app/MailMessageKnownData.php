<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Notifications\Messages\MailMessage;

final class MailMessageKnownData
{
    public function build(): MailMessage
    {
        // The call's data is fully known and names neither key, so the framework type still holds.
        return (new MailMessage())->view("mail-message-url-int", ["other" => 1]);
    }
}
