--FILE--
<?php declare(strict_types=1);

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class ExampleMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    /**
     * Create a new message instance.
     * @return void
     */
    public function __construct(public string $title)
    {
        //
    }

    /**
     * Get the message envelope.
     */
    public function envelope(): Envelope
    {
        return new Envelope(
            subject: $this->title,
        );
    }

    /**
     * Get the message content definition.
     */
    public function content(): Content
    {
        return new Content(
            view: 'emails.example',
        );
    }

    /**
     * Get the attachments for the message.
     * @return array
     */
    public function attachments(): array
    {
        return [];
    }
}

/**
 * Legacy build()-style mailable.
 *
 * Locks in coverage for the `Illuminate\Mail\Mailable => ['build']` entry. `build()` is
 * dispatched via `Container::getInstance()->call([$this, 'build'])` from a foreign scope,
 * so the visibility filter applies and only public overrides are suppressed.
 */
class LegacyExampleMail extends Mailable
{
    public function __construct() {}

    /** @return $this */
    public function build()
    {
        return $this->view('emails.legacy');
    }
}

/**
 * Negative-path fixture for `suppressFrameworkHookMethod()`.
 *
 * The visibility filter is meant to keep non-public framework hooks reported as real bugs.
 * A `protected function build()` cannot be reached by `Container::call([$this, 'build'])`
 * from BoundMethod's foreign scope, so under #869's findUnusedCode lock-in this method
 * should raise `PossiblyUnusedMethod` (proving the filter still fires). Until then it
 * is a smoke fixture documenting the intent.
 */
class ProtectedBuildMail extends Mailable
{
    public function __construct() {}

    /** @return $this */
    protected function build()
    {
        return $this->view('emails.bad');
    }
}
?>
--EXPECTF--
