--FILE--
<?php declare(strict_types=1);

// __() with a string literal key returns string|array (not mixed)
$_translated = __('messages.welcome');
/** @psalm-check-type-exact $_translated = array|string */

// __() with no args returns null
$_null = __();
/** @psalm-check-type-exact $_null = null */

// __() with null key returns null
$_nullKey = __(null);
/** @psalm-check-type-exact $_nullKey = null */

// trans() with a string literal key returns string|array
$_trans = trans('messages.welcome');
/** @psalm-check-type-exact $_trans = array|string */

// __() with a string variable key returns string|array
$key = 'messages.welcome';
$_dynamicTrans = __($key);
/** @psalm-check-type-exact $_dynamicTrans = array|string */

// __() with nullable key returns string|array|null
/** @var string|null $maybeKey */
$maybeKey = rand(0, 1) ? 'key' : null;
$_nullable = __($maybeKey);
/** @psalm-check-type-exact $_nullable = array|string|null */

// trans() with no args returns the Translator instance
$_translator = trans();
/** @psalm-check-type-exact $_translator = \Illuminate\Contracts\Translation\Translator */
?>
--EXPECT--
