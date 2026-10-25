<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Mail\Mailable;

final class MailableMarkdown extends Mailable
{
    public int $count = 0;

    public function build(): self
    {
        return $this->markdown('mail-markdown');
    }
}
