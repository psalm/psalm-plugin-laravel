<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Mail\Mailable;

final class MailableOpenData extends Mailable
{
    /**
     * @param array<string, mixed> $data
     */
    public function build(array $data): self
    {
        // The data may hold its own 'message', which markdown() merges after the injected one.
        return $this->markdown('mail-open', $data);
    }
}
