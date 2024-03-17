--FILE--
<?php declare(strict_types=1);

\App\Models\User::fakeQueryMethodThatDoesntExist();
?>
--EXPECTF--
UndefinedMagicMethod on line %d: Magic method App\Models\User::fakequerymethodthatdoesntexist does not exist
