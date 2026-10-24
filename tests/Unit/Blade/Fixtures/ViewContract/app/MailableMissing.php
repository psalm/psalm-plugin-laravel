<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Mail\Mailable;

final class MailableMissing extends Mailable
{
    public function __construct(public readonly string $title) {}

    public function build(): self
    {
        // `title` is supplied by the property; `absent` is not supplied by anything.
        return $this->markdown("mailable-missing");
    }
}
