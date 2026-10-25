<?php

declare(strict_types=1);

namespace ViewContractFixture;

final class MailMessageOverridePassed
{
    public function viaView(): CustomMailMessage
    {
        // CustomMailMessage overrides toArray(), which can replace `count`: the string passed here is
        // not what the template receives and is not judged against its `int` declaration.
        return (new CustomMailMessage())->view('mail-message-override-passed', ['count' => 'many']);
    }
}
