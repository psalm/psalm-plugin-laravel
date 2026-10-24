<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Mail\Mailable;

final class MailableTraitSelf extends Mailable
{
    use OwnerFields;

    public function __construct()
    {
        $this->owner = $this;
    }

    public function build(): self
    {
        // `self` in the trait names the Mailable that uses it, never the trait.
        return $this->view("mailable-owner");
    }
}
