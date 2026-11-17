--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Inline validate(): the rules are the `$rules` argument wherever a named call puts it. Reading the
 * first written argument (the messages) as the rules would drop the `email` rule's escape.
 *
 * @psalm-suppress MixedArgument
 */
function storeWithReorderedNamedArguments(Request $request): RedirectResponse {
    $request->validate(messages: ['email.email' => 'Enter a valid email.'], rules: ['email' => 'email']);

    return redirect()->to($request->input('email'));
}
?>
--EXPECTF--
