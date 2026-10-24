<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Mail\Mailable;

final class MailableFallback extends Mailable
{
    public int $count;

    public function build(): self
    {
        // An uninitialized property is skipped, so the passed value is what the template receives.
        return $this->view('mail-fallback', ['count' => 'fallback']);
    }
}
