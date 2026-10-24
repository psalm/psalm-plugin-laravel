<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Notifications\Messages\MailMessage;

final class MailMessageOpenData
{
    /** @param array<string, mixed> $data */
    public function url(array $data): MailMessage
    {
        // `data()` is `toArray() + viewData`: an unknown key of `$data` can replace `displayableActionUrl`.
        return (new MailMessage())->view("mail-message-url-int", $data);
    }

    /** @param array<string, mixed> $data */
    public function message(array $data): MailMessage
    {
        // The markdown renderer merges the call's data over the Mailer's `$message`.
        return (new MailMessage())->markdown("message-string", $data);
    }
}
