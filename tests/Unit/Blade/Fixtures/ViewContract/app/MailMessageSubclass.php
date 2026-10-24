<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Notifications\Messages\MailMessage;

final class MailMessageSubclass extends MailMessage
{
    /** @return array<string, mixed> */
    #[\Override]
    public function toArray()
    {
        return parent::toArray() + ["absent" => 1];
    }

    public function build(): self
    {
        // A subclass can add keys through toArray(), so nothing is provably absent.
        return $this->view("mail-message-absent");
    }
}
