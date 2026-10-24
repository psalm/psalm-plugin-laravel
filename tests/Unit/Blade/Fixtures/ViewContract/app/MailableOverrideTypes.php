<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Mail\Mailable;

final class MailableOverrideTypes extends Mailable
{
    public function buildViewData()
    {
        return ['message' => 5];
    }

    public function viaView(): self
    {
        // The Mailer sets `message` after buildViewData() ran, so an override cannot replace it.
        return $this->view('mail-message-string');
    }

    public function viaMarkdown(): self
    {
        // The markdown renderer lets the override's `message` win, and nothing says what it holds.
        return $this->markdown('mail-message-string');
    }
}
