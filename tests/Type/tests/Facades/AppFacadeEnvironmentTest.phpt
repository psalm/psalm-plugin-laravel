--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Facades\App;

// Illuminate\Support\Facades\App declares a native, unconditional
// `@method static string|bool environment(...)` tag. FacadeMethodHandler defers to a facade's
// own `@method` over the root Illuminate\Foundation\Application::environment() stub, which
// types the same method conditionally (list of names -> bool, no args -> string). That union
// leaked through App::environment() until the facade redeclared it as a REAL static method,
// which Psalm resolves natively before ever consulting `@method`.

function environment_check_narrows_to_bool(): bool
{
    /** @psalm-check-type-exact $result = bool */
    $result = App::environment(['production', 'testing']);

    return $result;
}

function environment_check_single_string_narrows_to_bool(): bool
{
    /** @psalm-check-type-exact $result = bool */
    $result = App::environment('production');

    return $result;
}

function environment_get_narrows_to_string(): string
{
    /** @psalm-check-type-exact $result = string */
    $result = App::environment();

    return $result;
}
?>
--EXPECTF--
