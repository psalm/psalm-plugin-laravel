--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm-with-optin-custom-issues.xml
--FILE--
<?php declare(strict_types=1);

// Ordering regression: MissingViewHandler is registered before ProducerReturnTypeHandler
// (Plugin::registerHandlers()) specifically so it gets first look at make() calls.
// MissingViewHandler's own return-type provider always answers null after emitting its
// diagnostic, so Psalm falls through to ProducerReturnTypeHandler for the type. Both
// observable effects — the MissingView issue AND the concrete narrowing — must survive.

$_missing = \Illuminate\Support\Facades\View::make('definitely-missing-view');
/** @psalm-check-type-exact $_missing = \Illuminate\View\View */

function _diFactory(\Illuminate\View\Factory $factory): void {
    $_alsoMissing = $factory->make('also-missing');
    /** @psalm-check-type-exact $_alsoMissing = \Illuminate\View\View */
}
?>
--EXPECTF--
MissingView on line %d: View 'definitely-missing-view' not found in any of the registered view paths
MissingView on line %d: View 'also-missing' not found in any of the registered view paths
