<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Mail\Mailable;

final class MailableWrongType extends Mailable
{
    public function __construct(public readonly int $count) {}

    public function build(): self
    {
        return $this->view("greeting-count");
    }
}
