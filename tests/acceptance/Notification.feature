Feature: Notification types
  Illuminate\Notifications\Notification have type support

  Background:
    Given I have the following config
      """
      <?xml version="1.0"?>
      <psalm totallyTyped="true">
        <projectFiles>
          <directory name="."/>
          <ignoreFiles> <directory name="../../vendor"/> </ignoreFiles>
        </projectFiles>
        <plugins>
          <pluginClass class="Psalm\LaravelPlugin\Plugin"/>
        </plugins>
      </psalm>
      """

  Scenario: "artisan make:notification ExampleNotification"
    Given I have the following code
      """
      <?php declare(strict_types=1);
      namespace App\Notifications;

      use Illuminate\Bus\Queueable;
      use Illuminate\Contracts\Queue\ShouldQueue;
      use Illuminate\Notifications\Messages\MailMessage;
      use Illuminate\Notifications\Notification;

      class ExampleNotification extends Notification
      {
          use Queueable;

          /**
           * Create a new notification instance.
           *
           * @return void
           */
          public function __construct()
          {
              //
          }

          /**
           * Get the notification's delivery channels.
           *
           * @param  mixed  $notifiable
           * @return array
           */
          public function via($notifiable)
          {
              return ['mail'];
          }

          /**
           * Get the mail representation of the notification.
           *
           * @param  mixed  $notifiable
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
           *
           * @param  mixed  $notifiable
           * @return array
           */
          public function toArray($notifiable)
          {
              return [
                  //
              ];
          }
      }
      """
    When I run Psalm
    Then I see no errors
