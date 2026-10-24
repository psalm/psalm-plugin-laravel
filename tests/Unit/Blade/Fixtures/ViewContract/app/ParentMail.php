<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Mail\Mailable;

class ParentMail extends Mailable
{
    public parent $owner;
}
