<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Notifications\Messages\MailMessage;

final class CustomMailMessage extends MailMessage
{
    public function toArray()
    {
        return ['custom' => true];
    }
}
