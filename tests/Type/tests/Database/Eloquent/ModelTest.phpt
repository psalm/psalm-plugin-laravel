--FILE--
<?php declare(strict_types=1);

use Illuminate\Foundation\Auth\User;

User::fakeQueryMethodThatDoesntExist();
?>
--EXPECTF--
UndefinedMagicMethod on line %d: Magic method Illuminate\Foundation\Auth\User::fakequerymethodthatdoesntexist does not exist
