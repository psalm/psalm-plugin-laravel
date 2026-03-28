--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm-find-translations.xml
--FILE--
<?php declare(strict_types=1);

// Missing translations via __() — should emit MissingTranslation
__('nonexistent.key');
__('auth.nonexistent');

// Missing translations via trans() — should emit MissingTranslation
trans('messages.missing');

// Existing translations — should not emit
__('auth.failed');
trans('auth.password');
__('auth.throttle');

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
