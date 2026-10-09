<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Notifications\Messages\MailMessage;

final class MailMessageChain
{
    public function build(): MailMessage
    {
        // SimpleMessage::with($line) appends a notification line; it binds no template data, so
        // 'name' is still missing here.
        return (new MailMessage())->view('greeting')->with('An intro line');
    }
}
