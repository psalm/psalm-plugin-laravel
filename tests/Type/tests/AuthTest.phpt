--FILE--
<?php declare(strict_types=1);

$_user = \Illuminate\Support\Facades\Auth::user();
/** @psalm-check-type-exact $_user = \Illuminate\Foundation\Auth\User|null */
?>
--EXPECT--
