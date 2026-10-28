<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Mail\Mailable;

final class MailableExternal
{
    public function typed(MailableMarkdown $mailable): void
    {
        // The receiver's class decides, not the class this method is in.
        $mailable->view('mail-external');
    }

    public function untyped(Mailable $mailable): void
    {
        // The base class says nothing about which public properties the instance has.
        $mailable->view('mail-unknown');
    }
}
