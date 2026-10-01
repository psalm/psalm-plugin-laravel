--FILE--
<?php declare(strict_types=1);

use Illuminate\Notifications\Notification;

// NotificationSender assigns the UUID at send time; before that the property is unset.

function ensure_id(Notification $notification): string
{
    /** @psalm-check-type-exact $id = null|string */
    $id = $notification->id;

    return $id ?? 'pending';
}
?>
--EXPECTF--
