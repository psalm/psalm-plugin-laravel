--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm-detect-views.xml
--FILE--
<?php declare(strict_types=1);

// Missing views via view() helper — should emit MissingView
view('nonexistent');
view('emails.welcom');

// No arguments — returns factory, should not emit
view();

// Namespaced views — should be skipped even if not found
view('mail::html.header');
view('notifications::email');

// View::make() via facade — note: facade calls go through __callStatic
// which may not resolve to Factory::make() at the Psalm level.
// The MethodReturnTypeProvider on Factory covers direct Factory usage.
?>
--EXPECTF--
MissingView on line %d: View 'nonexistent' not found in any of the registered view paths
MissingView on line %d: View 'emails.welcom' not found in any of the registered view paths
