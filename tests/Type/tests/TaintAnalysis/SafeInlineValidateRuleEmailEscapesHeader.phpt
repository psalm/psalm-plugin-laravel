--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
// Psalm 7.0.0-beta21 drops one of two taint flows that reach the same sink method from two
// different files in a co-analyzed batch, so this fixture's finding disappears when the suite
// runs as a whole while it is still reported on its own.
// @todo-by 2026-10-01 drop this gate once vimeo/psalm#11959 ships a fix; if the issue is still
// open then, re-check whether the batch still hides the finding and move the date.
// @see https://github.com/vimeo/psalm/issues/11959
\Tests\Psalm\LaravelPlugin\Type\PsalmVersion::skipOnRange('7.0.0-beta21', '7.0.0-beta22');
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Inline `$request->validate([...])` with `Rule::email()` carries the same
 * header/cookie escape as the 'email' string rule. TaintedSSRF is still
 * reported: a valid email address's domain can resolve to an internal host.
 */
/** @psalm-suppress MixedArgument */
function store(Request $request): RedirectResponse {
    $request->validate([
        'reply_to' => ['required', Rule::email()],
    ]);

    (new \Illuminate\Http\Client\PendingRequest())->get($request->input('reply_to'));
    return redirect()->to($request->input('reply_to'));
}
?>
--EXPECTF--
TaintedSSRF on line %d: Detected tainted network request
