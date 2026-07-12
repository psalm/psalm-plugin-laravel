--FILE--
<?php declare(strict_types=1);

// view() narrows past the stub's contract fallback: zero-arg narrows to the app's
// actual resolved factory class (the standard \Illuminate\View\Factory here), and
// argument-supplied calls narrow to the concrete \Illuminate\View\View.

$_factory = view();
/** @psalm-check-type-exact $_factory = \Illuminate\View\Factory */

$_shared = $_factory->getShared();
/** @psalm-check-type-exact $_shared = array<array-key, mixed> */

$_view = view('welcome');
/** @psalm-check-type-exact $_view = \Illuminate\View\View */

$_fragment = view('welcome')->fragment('x');
/** @psalm-check-type-exact $_fragment = string */

// func_num_args() === 0 is Laravel's own branch condition — a supplied `null` first
// argument still takes the "argument supplied" branch (view(null) !== view()).
$_viewFromNull = view(null);
/** @psalm-check-type-exact $_viewFromNull = \Illuminate\View\View */

$_viewWithData = view('welcome', ['k' => 'v'], []);
/** @psalm-check-type-exact $_viewWithData = \Illuminate\View\View */

// A trailing spread hides neither the count (provably >= 1) nor the view name,
// so the argument-supplied narrowing still applies.
/** @psalm-var list<array<string, mixed>> $trailing */
$trailing = [];
$_viewTrailingSpread = view('welcome', ...$trailing);
/** @psalm-check-type-exact $_viewTrailingSpread = \Illuminate\View\View */

// A leading spread hides the argument count: an empty spread returns the factory,
// a non-empty one returns a View. The result is the sound union of both contracts,
// NOT a bare View — otherwise a concrete-only call would be falsely accepted on
// what is actually the factory at runtime.
/** @psalm-var list{0?: string} $args */
$args = [];
$_viewLeadingSpread = view(...$args);
/** @psalm-check-type-exact $_viewLeadingSpread = \Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View */
?>
--EXPECTF--
