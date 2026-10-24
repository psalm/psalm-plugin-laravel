<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Mail\Mailable;

abstract class MailableBase extends Mailable
{
    public function build(): self
    {
        // A subclass can add public properties this base class does not declare, so nothing is
        // provably absent here.
        return $this->view("mailable-missing");
    }
}
