<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Notifications\Messages\MailMessage;

final class MailMessageDataOverride extends MailMessage
{
    /** @return array<string, mixed> */
    #[\Override]
    public function data()
    {
        return parent::data() + ["extra" => 1];
    }

    public function build(): self
    {
        // Only data() is overridden (toArray() is not), and it adds the `extra` the template declares.
        return $this->view("mailable-extra");
    }
}
