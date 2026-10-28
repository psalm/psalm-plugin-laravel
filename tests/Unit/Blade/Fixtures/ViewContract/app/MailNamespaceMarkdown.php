<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Messages\MailMessage;

final class MailNamespaceMarkdown extends Mailable
{
    public function build(): self
    {
        return $this->markdown('mail::widget');
    }

    public function message(): MailMessage
    {
        return (new MailMessage())->markdown('mail::widget');
    }

    /** A plain render resolves `mail` through the boot-time hint: checked. */
    public function plain(): View
    {
        return view('mail::widget');
    }
}
