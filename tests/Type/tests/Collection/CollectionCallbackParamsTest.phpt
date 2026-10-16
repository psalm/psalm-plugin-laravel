--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\LazyCollection;

/**
 * Untyped closure literals passed to the Collection callbacks whose stub callable cannot name the
 * call-site receiver: `tap()`, `pipe()`, the `whenEmpty()` family and `chunkWhile()`. The untyped
 * param must resolve to the receiver (with its generics) instead of reporting InvalidArgument or
 * degrading to `mixed`. Typed closures must keep working. Declines and wrong-shape closures live in
 * CollectionCallbackParamsDeclineTest.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1900
 */

function callback_params_sink(mixed $_value): void {}

/** @extends Collection<int, Customer> */
final class CallbackParamsCustomerList extends Collection {}

/**
 * @template TFirst
 * @template TSecond of array-key
 * @extends Collection<TSecond, TFirst>
 */
class CallbackParamsReordered extends Collection
{
    /** @return static<TFirst, TSecond> */
    public function selfTap(): static
    {
        return $this->tap(function ($x) {
            /** @psalm-check-type-exact $x = CallbackParamsReordered<TFirst, TSecond>&static */
            callback_params_sink($x);
            return $x;
        });
    }
}

/** @param Collection<int, Customer> $c */
function test_collection_tap(Collection $c): void
{
    $r = $c->tap(function ($x) {
        /** @psalm-check-type-exact $x = Collection<int, Customer> */
        callback_params_sink($x);
        return $x;
    });
    /** @psalm-check-type-exact $r = Collection<int, Customer>&static */
    callback_params_sink($r);

    $c->tap(fn ($x) => $x->count());
    $c->tap(function (Collection $x): void {
        callback_params_sink($x);
    });
    $c->tap(function (): void {});
}

/** @param Collection<int, Customer> $c */
function test_collection_pipe(Collection $c): void
{
    $r = $c->pipe(function ($x) {
        /** @psalm-check-type-exact $x = Collection<int, Customer> */
        callback_params_sink($x);
        return 1;
    });
    /** @psalm-check-type-exact $r = 1 */
    callback_params_sink($r);

    $typed = $c->pipe(fn (Collection $x) => $x->count());
    /** @psalm-check-type-exact $typed = int<0, max> */
    callback_params_sink($typed);
    $c->pipe(fn ($x) => $x->count());
}

/** @param Collection<int, Customer> $c */
function test_collection_chunk_while(Collection $c): void
{
    $r = $c->chunkWhile(function ($v, $k, $chunk) {
        /** @psalm-check-type-exact $v = Customer */
        callback_params_sink($v);
        /** @psalm-check-type-exact $k = int */
        callback_params_sink($k);
        /** @psalm-check-type-exact $chunk = Collection<int, Customer> */
        callback_params_sink($chunk);
        return true;
    });
    /** @psalm-check-type-exact $r = Collection<int, Collection<int, Customer>&static>&static */
    callback_params_sink($r);

    $c->chunkWhile(fn ($v, $k, $chunk) => $chunk->count() < 3);
    $c->chunkWhile(fn (Customer $v, int $k, Collection $chunk): bool => $chunk->isEmpty());
    $c->chunkWhile(fn ($v) => $v->id > 0);
}

/** @param Collection<int, Customer> $c */
function test_collection_empty_family(Collection $c): void
{
    $a = $c->whenEmpty(function ($x) {
        /** @psalm-check-type-exact $x = Collection<int, Customer> */
        callback_params_sink($x);
        return 1;
    });
    /** @psalm-check-type-exact $a = 1|Collection<int, Customer>&static */
    callback_params_sink($a);

    $b = $c->whenNotEmpty(function ($x) {
        /** @psalm-check-type-exact $x = Collection<int, Customer> */
        callback_params_sink($x);
        return 1;
    }, function ($x) {
        /** @psalm-check-type-exact $x = Collection<int, Customer> */
        callback_params_sink($x);
    });
    /** @psalm-check-type-exact $b = 1|Collection<int, Customer>&static */
    callback_params_sink($b);

    $c->unlessEmpty(function ($x) {
        /** @psalm-check-type-exact $x = Collection<int, Customer> */
        callback_params_sink($x);
    });
    $c->unlessNotEmpty(function ($x) {
        /** @psalm-check-type-exact $x = Collection<int, Customer> */
        callback_params_sink($x);
    });

    $typed = $c->whenNotEmpty(fn (Collection $x): int => $x->count());
    /** @psalm-check-type-exact $typed = Collection<int, Customer>&static|int<0, max> */
    callback_params_sink($typed);
    $c->whenEmpty(fn ($x) => callback_params_sink($x));
    $c->whenEmpty(callback: fn ($x) => $x->count(), default: fn ($x) => $x->count());

    $c->whenEmpty(function ($x, $value) {
        /** @psalm-check-type-exact $value = true */
        callback_params_sink($value);
    }, function ($x, $value) {
        /** @psalm-check-type-exact $value = false */
        callback_params_sink($value);
    });
    $c->unlessNotEmpty(fn ($x, bool $condition) => $condition);

    $named = $c->whenEmpty(default: fn () => null, callback: fn () => 'yes');
    /** @psalm-check-type-exact $named = 'yes'|Collection<int, Customer>&static */
    callback_params_sink($named);
    $withDefault = $c->whenEmpty(fn () => null, fn () => 'fallback');
    /** @psalm-check-type-exact $withDefault = 'fallback'|Collection<int, Customer>&static */
    callback_params_sink($withDefault);
    $whenDefault = $c->when(true, fn () => null, fn () => 'fallback');
    /** @psalm-check-type-exact $whenDefault = 'fallback'|Collection<int, Customer>&static */
    callback_params_sink($whenDefault);
    $sibling = $c->whenEmpty(fn (): Collection => collect(['key' => 'text']));
    $first = $sibling->first();
    /** @psalm-check-type-exact $first = 'text'|Customer|null */
    callback_params_sink($first);
    $c->whenEmpty(function ($x, ?bool $condition = null): void {
        callback_params_sink($condition);
    });
    $c->whenEmpty(function ($x, bool $condition): void {
        callback_params_sink($condition);
    });
    $trailingUnpack = $c->when(true, fn () => 'yes', ...[null]);
    /** @psalm-check-type-exact $trailingUnpack = 'yes'|Collection<int, Customer>&static */
    callback_params_sink($trailingUnpack);
    $namedWhen = $c->when(true, default: fn () => 7, callback: fn () => 'yes');
    /** @psalm-check-type-exact $namedWhen = 'yes'|7|Collection<int, Customer>&static */
    callback_params_sink($namedWhen);
}

/** @param LazyCollection<int, Customer> $l */
function test_lazy_collection(LazyCollection $l): void
{
    $l->tap(function ($x) {
        /** @psalm-check-type-exact $x = LazyCollection<int, Customer> */
        callback_params_sink($x);
    });
    $r = $l->pipe(function ($x) {
        /** @psalm-check-type-exact $x = LazyCollection<int, Customer> */
        callback_params_sink($x);
        return 'a';
    });
    /** @psalm-check-type-exact $r = 'a' */
    callback_params_sink($r);
    $l->whenEmpty(function ($x) {
        /** @psalm-check-type-exact $x = LazyCollection<int, Customer> */
        callback_params_sink($x);
    });
    $l->chunkWhile(function ($v, $k, $chunk) {
        /** @psalm-check-type-exact $chunk = Collection<int, Customer> */
        callback_params_sink($chunk);
        return true;
    });
}

/** @param EloquentCollection<int, Customer> $e */
function test_eloquent_collection(EloquentCollection $e): void
{
    $e->tap(function ($x) {
        /** @psalm-check-type-exact $x = EloquentCollection<int, Customer> */
        callback_params_sink($x);
    });
    $e->pipe(function ($x) {
        /** @psalm-check-type-exact $x = EloquentCollection<int, Customer> */
        callback_params_sink($x);
        return 1;
    });
    $e->whenNotEmpty(function ($x) {
        /** @psalm-check-type-exact $x = EloquentCollection<int, Customer> */
        callback_params_sink($x);
    });
    $e->chunkWhile(function ($v, $k, $chunk) {
        /** @psalm-check-type-exact $v = Customer */
        callback_params_sink($v);
        /** @psalm-check-type-exact $chunk = Collection<int, Customer> */
        callback_params_sink($chunk);
        callback_params_sink($k);
        return true;
    });
}

function test_subclasses(CallbackParamsCustomerList $list): void
{
    $list->tap(function ($x) {
        /** @psalm-check-type-exact $x = CallbackParamsCustomerList */
        callback_params_sink($x);
    });
    $list->pipe(function ($x) {
        /** @psalm-check-type-exact $x = CallbackParamsCustomerList */
        callback_params_sink($x);
        return 1;
    });
    $list->whenEmpty(function ($x) {
        /** @psalm-check-type-exact $x = CallbackParamsCustomerList */
        callback_params_sink($x);
    });
    $list->chunkWhile(function ($v, $k, $chunk) {
        /** @psalm-check-type-exact $v = Customer */
        callback_params_sink($v);
        /** @psalm-check-type-exact $chunk = Collection<int, Customer> */
        callback_params_sink($chunk);
        callback_params_sink($k);
        return true;
    });
}

/** @param CallbackParamsReordered<Customer, int> $r */
function test_reordered_templates(CallbackParamsReordered $r): void
{
    $r->tap(function ($x) {
        /** @psalm-check-type-exact $x = CallbackParamsReordered<Customer, int> */
        callback_params_sink($x);
    });
    $r->chunkWhile(function ($v, $k, $chunk) {
        /** @psalm-check-type-exact $v = Customer */
        callback_params_sink($v);
        /** @psalm-check-type-exact $chunk = Collection<int, Customer> */
        callback_params_sink($chunk);
        return true;
    });
}

function test_chained_and_nullsafe(?Collection $maybe): void
{
    collect([1, 2])->tap(function ($x) {
        /** @psalm-check-type-exact $x = Collection<int<0, 1>, 1|2> */
        callback_params_sink($x);
    });
    Collection::make(['a'])->pipe(function ($x) {
        /** @psalm-check-type-exact $x = Collection<int<0, 0>, 'a'> */
        callback_params_sink($x);
        return 1;
    });
    $maybe?->tap(function ($x) {
        /** @psalm-check-type-exact $x = Collection<array-key, mixed> */
        callback_params_sink($x);
    });
}
--EXPECTF--
