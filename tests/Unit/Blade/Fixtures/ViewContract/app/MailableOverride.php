<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Mail\Mailable;

final class MailableOverride extends Mailable
{
    public int $count = 1;

    public function buildViewData()
    {
        return ['count' => 'ready', 'extra' => true];
    }

    public function build(): self
    {
        return $this->view('mail-override-data');
    }
}
