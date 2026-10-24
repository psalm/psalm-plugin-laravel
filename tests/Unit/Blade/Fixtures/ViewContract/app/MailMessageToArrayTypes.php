<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Notifications\Messages\MailMessage;

final class MailMessageToArrayTypes extends MailMessage
{
    /** @return array<string, mixed> */
    #[\Override]
    public function toArray()
    {
        return ["displayableActionUrl" => 42] + parent::toArray();
    }

    public function build(): self
    {
        return $this->view("mail-message-url-int");
    }

    public function viaMarkdown(): self
    {
        return $this->markdown("message-string");
    }
}
