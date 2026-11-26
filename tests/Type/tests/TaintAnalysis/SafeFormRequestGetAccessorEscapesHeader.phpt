--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('13.0.0');
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\RedirectResponse;

/**
 * `get('key')` on a FormRequest reads a field its rules() cover: an `email` rule
 * escapes the header taint for it, as for `input('key')`. The unvalidated key
 * stays tainted.
 */
final class InviteRequest extends FormRequest
{
    public function rules(): array
    {
        return ['email' => ['required', 'email']];
    }
}

/** @psalm-suppress DeprecatedMethod Laravel 13 deprecates get() */
function invite(InviteRequest $request): RedirectResponse {
    return redirect()->to('/')
        ->header('X-Contact', (string) $request->get('email'))
        ->header('X-Referrer', (string) $request->get('referrer'));
}
?>
--EXPECTF--
TaintedHeader on line %d: Detected tainted header
