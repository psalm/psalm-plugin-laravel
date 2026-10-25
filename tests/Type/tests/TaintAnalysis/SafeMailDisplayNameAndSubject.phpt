--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\Request;
use Illuminate\Mail\Mailable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/**
 * Mail display names and subjects are not header sinks (#1958): Symfony's `Address` strips CR/LF
 * from the name, and the subject header is RFC 2047-encoded, so neither can inject a header.
 * Only the recipient address is a sink; `mailableToAddressControl()` below is the positive control
 * that keeps every negative here from passing vacuously.
 */

function mailableFromName(Request $request): void
{
    (new Mailable())->from('safe@example.com', (string) $request->input('name'));
}

function mailableToName(Request $request): void
{
    (new Mailable())->to('safe@example.com', (string) $request->input('name'));
}

function mailableCcName(Request $request): void
{
    (new Mailable())->cc('safe@example.com', (string) $request->input('name'));
}

function mailableBccName(Request $request): void
{
    (new Mailable())->bcc('safe@example.com', (string) $request->input('name'));
}

function mailableReplyToName(Request $request): void
{
    (new Mailable())->replyTo('safe@example.com', (string) $request->input('name'));
}

function mailableSubject(Request $request): void
{
    (new Mailable())->subject((string) $request->input('subject'));
}

function mailMessageFromName(Request $request): void
{
    (new MailMessage())->from('safe@example.com', (string) $request->input('name'));
}

function mailMessageReplyToName(Request $request): void
{
    (new MailMessage())->replyTo('safe@example.com', (string) $request->input('name'));
}

function mailMessageCcName(Request $request): void
{
    (new MailMessage())->cc('safe@example.com', (string) $request->input('name'));
}

function mailMessageBccName(Request $request): void
{
    (new MailMessage())->bcc('safe@example.com', (string) $request->input('name'));
}

function mailMessageSubject(Request $request): void
{
    (new MailMessage())->subject((string) $request->input('subject'));
}

/** The reproduction from #1958: a request value flows through a promoted property into `replyTo()`'s name. */
final class WelcomeNotification extends Notification
{
    public function __construct(private readonly string $name) {}

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage())->replyTo('support@example.com', $this->name);
    }
}

function sendWelcomeNotification(): void
{
    new WelcomeNotification((string) request()->input('full_name'));
}

/** Positive control: the address argument is still a header sink. */
function mailableToAddressControl(Request $request): void
{
    (new Mailable())->to((string) $request->input('email'));
}
?>
--EXPECTF--
TaintedHeader on line %d: Detected tainted header
