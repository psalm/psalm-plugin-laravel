--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm-with-optin-custom-issues.xml
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

// Direct Factory::make() — should emit MissingView
function test_factory_make(\Illuminate\View\Factory $factory): void {
    $factory->make('nonexistent-direct');
}

// View facade — should emit MissingView (handler registers for facade classes)
\View::make('nonexistent-facade');
\Illuminate\Support\Facades\View::make('nonexistent-fqcn');

// View facade with valid views — should not emit
\View::make('welcome');
\Illuminate\Support\Facades\View::make('welcome');

// Namespaced views via facade — should be skipped even if not found
\View::make('mail::html.header');
\Illuminate\Support\Facades\View::make('notifications::email');
?>
--EXPECTF--
MissingView on line %d: View 'nonexistent' not found in any of the registered view paths
MissingView on line %d: View 'emails.welcom' not found in any of the registered view paths
MissingView on line %d: View 'nonexistent-direct' not found in any of the registered view paths
MissingView on line %d: View 'nonexistent-facade' not found in any of the registered view paths
MissingView on line %d: View 'nonexistent-fqcn' not found in any of the registered view paths
