--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm-translations-short.xml
--FILE--
<?php declare(strict_types=1);

// keys="short": dotted `group.key` references are still checked
__('mesages.welcome');
trans('messages.missing');

// Sentence-style keys (whitespace) are skipped
__('Online courses - more coming up!');

// Empty trailing segment: plain text that happens to end with a period
__('Done.');

// No dot: a plain word used as a JSON key
__('Dashboard');
trans('Dashboard');

// Type narrowing is unaffected for existing keys
$failed = __('auth.failed');
/** @psalm-check-type-exact $failed = non-empty-string */
echo $failed;
?>
--EXPECTF--
MissingTranslation on line %d: Translation key 'mesages.welcome' not found in language files
MissingTranslation on line %d: Translation key 'messages.missing' not found in language files
