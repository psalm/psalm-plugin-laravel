<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Mail\Mailable;

final class MailableAdditionalData extends Mailable
{
    /** @return array<string, mixed> */
    #[\Override]
    protected function additionalMessageData(): array
    {
        return parent::additionalMessageData() + ["extra" => 1];
    }

    public function build(): self
    {
        // Only additionalMessageData() is overridden, and it adds the `extra` the template declares.
        return $this->view("mailable-extra");
    }
}
