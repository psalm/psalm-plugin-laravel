--FILE--
<?php declare(strict_types=1);

use Illuminate\Contracts\Support\MessageBag;

// The contract documents a bare `array`; the concrete MessageBag::all() returns array<string>.

/** @return array<string> */
function all_messages(MessageBag $bag): array
{
    /** @psalm-check-type-exact $messages = array<array-key, string> */
    $messages = $bag->all();

    return $messages;
}
?>
--EXPECTF--
