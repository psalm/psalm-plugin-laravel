<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

final class NotificationMail extends Notification
{
    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())->view('mail-notification');
    }

    public function toMarkdown(object $notifiable): MailMessage
    {
        // MailMessage::data() is array_merge(toArray(), viewData): the passed 'greeting' wins.
        return (new MailMessage())->markdown('mail-notification-passed', ['greeting' => 5]);
    }

    public function toCustom(object $notifiable): CustomMailMessage
    {
        return (new CustomMailMessage())->view('mail-override');
    }
}
