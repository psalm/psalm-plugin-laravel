<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Mail\Mailable;

final class MailableChain extends Mailable
{
    public function build(): self
    {
        return $this->view('greeting')->with('name', 123);
    }
}
