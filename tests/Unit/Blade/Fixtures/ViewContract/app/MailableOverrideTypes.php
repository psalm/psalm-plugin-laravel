<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Mail\Mailable;

final class MailableOverrideTypes extends Mailable
{
    public int $count = 3;

    /** @return array<string, mixed> */
    #[\Override]
    public function buildViewData()
    {
        return ["count" => "ok"];
    }

    public function build(): self
    {
        // The override supplies a string for `count`, whatever the property declares.
        return $this->view("greeting-count");
    }

    public function viaMarkdown(): self
    {
        // The override can return `message` too, and the markdown renderer lets it win.
        return $this->markdown("message-string");
    }

    public function viaView(): self
    {
        // The Mailer sets `message` after buildViewData() ran, so an override cannot replace it.
        return $this->view("message-string");
    }
}
