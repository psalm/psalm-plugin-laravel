<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Mail\Mailable;

final class MailableProps extends Mailable
{
    public string $greeting = 'hi';

    public static int $sent = 0;

    public function __construct(public Thing $order, public string $note = 'n', public string $unread = 'u') {}

    public function build(): self
    {
        // Mailable::buildViewData() merges every public property into the view; 'extra' is passed,
        // 'unread' is only supplied, so only the former can be an unused key.
        return $this->view('mail-props')->with('extra', 1);
    }
}
