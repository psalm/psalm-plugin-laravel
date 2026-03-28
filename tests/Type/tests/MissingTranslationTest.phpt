--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm-find-translations.xml
--FILE--
<?php declare(strict_types=1);

// Missing translations via __() — should emit MissingTranslation
__('nonexistent.key');
__('auth.nonexistent');

// Missing translations via trans() — should emit MissingTranslation
trans('messages.missing');

// Existing translations — should return narrowed string type
$failed = __('auth.failed');
/** @psalm-check-type-exact $failed = string */
echo $failed;

$password = trans('auth.password');
/** @psalm-check-type-exact $password = string */
echo $password;

$throttle = __('auth.throttle');
/** @psalm-check-type-exact $throttle = string */
echo $throttle;

// Existing translations — array type (validation.between is an array in Laravel)
$between = __('validation.between');
/** @psalm-check-type-exact $between = array<array-key, mixed> */
print_r($between);

// No arguments — should not emit
__();

// Namespaced package keys — should be skipped even if not found
__('package::file.key');
trans('notifications::email.greeting');

// Dynamic keys — should be skipped
$key = 'auth.failed';
__($key);
?>
--EXPECTF--
MissingTranslation on line %d: Translation key 'nonexistent.key' not found in language files
MissingTranslation on line %d: Translation key 'auth.nonexistent' not found in language files
MissingTranslation on line %d: Translation key 'messages.missing' not found in language files
