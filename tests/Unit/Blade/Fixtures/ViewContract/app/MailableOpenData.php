<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Mail\Mailable;

final class MailableOpenData extends Mailable
{
    /** @param array<string, mixed> $data */
    public function open(array $data): self
    {
        // An unknown key of `$data` can be `message`, which beats the Mailer's on the markdown path.
        return $this->markdown("message-string", $data);
    }
}
