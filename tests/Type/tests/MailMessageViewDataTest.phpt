--FILE--
<?php declare(strict_types=1);

use Illuminate\Notifications\Messages\MailMessage;

function _viewData(): void {
    $m = (new MailMessage())->view('x', ['a' => 1]);
    $d = $m->viewData;
    /** @psalm-check-type-exact $d = array<string, mixed> */
    echo count($d);

    $m->view('x', ['a', 'b']);
    $m->markdown('x', ['a', 'b']);
    $m->text('x', ['a', 'b']);
}
?>
--EXPECTF--
InvalidArgument on line %d: Argument 2 of Illuminate\Notifications\Messages\MailMessage::view expects array<string, mixed>, but list{'a', 'b'} provided
InvalidArgument on line %d: Argument 2 of Illuminate\Notifications\Messages\MailMessage::markdown expects array<string, mixed>, but list{'a', 'b'} provided
InvalidArgument on line %d: Argument 2 of Illuminate\Notifications\Messages\MailMessage::text expects array<string, mixed>, but list{'a', 'b'} provided
