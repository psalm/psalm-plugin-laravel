--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use App\Models\Vehicle;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

/**
 * What CollectionCallbackParamsHandler must leave to the stub signature (so the pre-existing
 * findings stay), and closures of the wrong shape, which must stay reported. The positive cases
 * live in CollectionCallbackParamsTest.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1900
 */

function decline_sink(mixed $_value): bool
{
    return true;
}

/** @param Collection<int, Customer>|Collection<int, Vehicle> $u */
function test_union_receiver_declines(Collection $u): void
{
    $u->tap(fn ($x) => decline_sink($x));
}

/** @param Collection<int, Customer> $c */
function test_wrong_shape_is_reported(Collection $c): void
{
    $c->tap(fn (Customer $x) => decline_sink($x));
    $c->tap(fn ($x, $extra) => decline_sink($x) && decline_sink($extra));
    $c->pipe(fn (Customer $x) => decline_sink($x));
    $c->chunkWhile(fn ($v, $k, Customer $chunk) => decline_sink($chunk));
}

/** @param Collection<int, Customer> $c */
function test_empty_family_third_param_is_reported(Collection $c): void
{
    $c->whenEmpty(fn ($x, $condition, $third) => decline_sink($third));
}

/** @param LazyCollection<int, Customer> $l */
function test_lazy_chunk_is_a_collection(LazyCollection $l): void
{
    $l->chunkWhile(fn ($v, $k, LazyCollection $chunk) => decline_sink($chunk));
}

/** @param Collection<int, Customer> $c */
function test_non_literal_callables_are_untouched(Collection $c): void
{
    $c->tap(decline_sink(...));
    $c->tap('decline_sink');
    $c->whenEmpty('decline_sink');
}

/** @param Collection<int, Customer> $c */
function test_default_and_variadic_params_decline(Collection $c): void
{
    $c->tap(fn ($x = null) => decline_sink($x));
    $c->tap(fn (...$xs) => decline_sink($xs));
    $c->chunkWhile(fn ($v, $k, $chunk = null) => decline_sink($chunk));
}

/** @param Collection<int, Customer> $c */
function test_unpacked_args_decline(Collection $c): void
{
    $args = [fn (Collection $x): bool => decline_sink($x)];
    $c->tap(...$args);
}
--EXPECTF--
InvalidArgument on line %d: Argument 1 of Illuminate\Support\Collection::tap expects callable[impure](Illuminate\Support\Collection<array-key, mixed>):mixed, but Closure[impure](static):bool provided
InvalidArgument on line %d: Argument 1 of Illuminate\Support\Collection::tap expects callable[impure](Illuminate\Support\Collection<array-key, mixed>):mixed, but Closure[impure](App\Models\Customer):bool provided
InvalidScalarArgument on line %d: Argument 1 of Illuminate\Support\Collection::tap expects callable[impure](Illuminate\Support\Collection<int, App\Models\Customer>):mixed, but Closure[impure](Illuminate\Support\Collection<int, App\Models\Customer>, mixed):bool provided
MissingClosureParamType on line %d: Parameter $extra has no provided type
InvalidArgument on line %d: Argument 1 of Illuminate\Support\Collection::pipe expects callable[impure](Illuminate\Support\Collection<array-key, mixed>):bool, but Closure[impure](App\Models\Customer):bool provided
InvalidArgument on line %d: Argument 1 of Illuminate\Support\Collection::chunkWhile expects callable[impure](App\Models\Customer, int, Illuminate\Support\Collection<int, App\Models\Customer>&static):bool, but Closure[impure](App\Models\Customer, int, App\Models\Customer):bool provided
InvalidArgument on line %d: Argument 1 of Illuminate\Support\Collection::whenEmpty expects callable[impure](Illuminate\Support\Collection<int, App\Models\Customer>, true), but Closure[impure](Illuminate\Support\Collection<int, App\Models\Customer>, true, mixed):bool provided
MissingClosureParamType on line %d: Parameter $third has no provided type
InvalidArgument on line %d: Argument 1 of Illuminate\Support\LazyCollection::chunkWhile expects callable[impure](App\Models\Customer, int, Illuminate\Support\Collection<int, App\Models\Customer>):bool, but Closure[impure](App\Models\Customer, int, Illuminate\Support\LazyCollection):bool provided
InvalidArgument on line %d: Argument 1 of Illuminate\Support\Collection::tap expects callable[impure](Illuminate\Support\Collection<array-key, mixed>):mixed, but Closure[impure](static=):bool provided
InvalidArgument on line %d: Argument 1 of Illuminate\Support\Collection::tap expects callable[impure](Illuminate\Support\Collection<array-key, mixed>):mixed, but Closure[impure](static...):bool provided
InvalidArgument on line %d: Argument 1 of Illuminate\Support\Collection::chunkWhile expects callable[impure](App\Models\Customer, int, Illuminate\Support\Collection<int, App\Models\Customer>&static):bool, but Closure[impure](App\Models\Customer, int, static<int, App\Models\Customer>=):bool provided
