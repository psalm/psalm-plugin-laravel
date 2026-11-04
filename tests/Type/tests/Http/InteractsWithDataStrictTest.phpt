--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('13.40.0');
--FILE--
<?php declare(strict_types=1);

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Stringable;
use Illuminate\Support\ValidatedInput;

// Laravel 13.40 added `$strict` to str(), string(), boolean(), integer() and float() (laravel/framework#62254).
// Without it in the stubs, a call passing the flag is TooManyArguments.

function request_casts_accept_strict(Request $request): void
{
    $_str = $request->str('name', null, true);
    /** @psalm-check-type-exact $_str = Stringable */

    $_string = $request->string('name', null, true);
    /** @psalm-check-type-exact $_string = Stringable */

    $_boolean = $request->boolean('flag', false, true);
    /** @psalm-check-type-exact $_boolean = bool */

    $_integer = $request->integer('count', 0, true);
    /** @psalm-check-type-exact $_integer = int */

    $_float = $request->float('ratio', 0.0, true);
    /** @psalm-check-type-exact $_float = float */
}

/** @param ValidatedInput<\Illuminate\Foundation\Http\FormRequest> $input */
function validated_input_casts_accept_strict(ValidatedInput $input): void
{
    $_str = $input->str('name', null, true);
    /** @psalm-check-type-exact $_str = Stringable */

    $_string = $input->string('name', null, true);
    /** @psalm-check-type-exact $_string = Stringable */

    $_boolean = $input->boolean('flag', false, true);
    /** @psalm-check-type-exact $_boolean = bool */

    $_integer = $input->integer('count', 0, true);
    /** @psalm-check-type-exact $_integer = int */

    $_float = $input->float('ratio', 0.0, true);
    /** @psalm-check-type-exact $_float = float */
}

// An override documented only by `{@inheritDoc}` inherits `$strict`'s type from the stub.
final class StrictBooleanRequest extends FormRequest
{
    /** {@inheritDoc} */
    #[\Override]
    public function boolean($key = null, $default = false, $strict = false): bool
    {
        return parent::boolean($key, $default, $strict);
    }
}
?>
--EXPECTF--
