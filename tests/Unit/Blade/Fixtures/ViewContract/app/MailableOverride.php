<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Mail\Mailable;

final class MailableOverride extends Mailable
{
    /** @return array<string, mixed> */
    #[\Override]
    public function buildViewData()
    {
        return ["absent" => 1, "title" => "t"];
    }

    public function build(): self
    {
        return $this->view("mailable-missing");
    }
}
