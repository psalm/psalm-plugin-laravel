--FILE--
<?php declare(strict_types=1);

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Messages\DatabaseMessage;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ExampleNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new notification instance.
     * @return void
     */
    public function __construct()
    {
        //
    }

    /**
     * Get the notification's delivery channels.
     * @param mixed $notifiable
     * @return array
     */
    public function via($notifiable)
    {
        return ['mail'];
    }

    /**
     * Determine if the notification should be sent.
     * @param mixed $notifiable
     */
    public function shouldSend($notifiable, string $channel): bool
    {
        return true;
    }

    /**
     * Get the connections the notification should be sent on per channel.
     * @return array<string, string>
     */
    public function viaConnections(): array
    {
        return ['mail' => 'sync'];
    }

    /**
     * Get the queues the notification should be queued on per channel.
     * @return array<string, string>
     */
    public function viaQueues(): array
    {
        return ['mail' => 'default'];
    }

    /**
     * Get the mail representation of the notification.
     * @param mixed $notifiable
     * @return \Illuminate\Notifications\Messages\MailMessage
     */
    public function toMail($notifiable)
    {
        return (new MailMessage)
            ->line('The introduction to the notification.')
            ->action('Notification Action', url('/'))
            ->line('Thank you for using our application!');
    }

    /**
     * Get the array representation of the notification.
     * @param mixed $notifiable
     * @return array
     */
    public function toArray($notifiable)
    {
        return [
            //
        ];
    }

    /**
     * Get the database representation of the notification.
     * @param mixed $notifiable
     */
    public function toDatabase($notifiable): DatabaseMessage
    {
        return new DatabaseMessage([]);
    }

    /**
     * Get the broadcastable representation of the notification.
     * @param mixed $notifiable
     */
    public function toBroadcast($notifiable): BroadcastMessage
    {
        return new BroadcastMessage([]);
    }

    /**
     * Get the channels the notification should broadcast on.
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    #[\Override]
    public function broadcastOn(): array
    {
        return [];
    }

    /**
     * Get the data to broadcast.
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [];
    }

    /**
     * Get the broadcast event name.
     */
    public function broadcastAs(): string
    {
        return 'example.notification';
    }

    /**
     * Custom channel render method — community/user-defined channels can add any toXxx() name.
     * @param mixed $notifiable
     * @return array<string, mixed>
     */
    public function toCustomChannel($notifiable): array
    {
        return [];
    }
}
--EXPECTF--
