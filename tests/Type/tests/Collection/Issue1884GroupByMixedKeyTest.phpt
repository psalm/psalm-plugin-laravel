--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Collection;
use App\Models\Customer;

/** @extends Collection<string, Customer> */
final class Issue1884FixedCollection extends Collection {}

/**
 * @template V
 * @template K of array-key
 * @extends Collection<K, V>
 */
final class Issue1884Reversed extends Collection {}

/** @param Issue1884Reversed<Customer, string> $c */
function remapped_template_subclass_defers(Issue1884Reversed $c): void
{
    $_keyed = $c->keyBy(fn ($r) => $r->unknown_prop ?? 'unknown');
    /** @psalm-check-type-exact $_keyed = Issue1884Reversed<array-key|mixed, Customer> */
}

/**
 * @param Collection<int, Customer> $c
 * @param (callable(Customer): mixed)|list<string> $by
 */
function callback_or_array_union_defers(Collection $c, callable|array $by): void
{
    $_grouped = $c->groupBy($by);
    /** @psalm-check-type-exact $_grouped = Collection<array-key, Collection<int, mixed>&static>&static */
}

/** @param Collection<int, Customer> $c */
function mixed_group_by_key_resolves_to_array_key(Collection $c): void
{
    $c->groupBy(fn ($r) => $r->unknown_prop ?? 'unknown')
        ->map(fn ($g) => $g->count())
        ->sortByDesc(fn (int $n) => $n);

    $_grouped = $c->groupBy(fn ($r): mixed => $r->unknown_prop ?? 'unknown');
    /** @psalm-check-type-exact $_grouped = Collection<array-key, Collection<int, Customer>&static>&static */

    $_preserved = $c->groupBy(fn ($r) => $r->unknown_prop ?? 'unknown', true);
    /** @psalm-check-type-exact $_preserved = Collection<array-key, Collection<int, Customer>&static>&static */
}

/** @param Collection<string, Customer> $c */
function mixed_group_by_preserve_keys(Collection $c): void
{
    $_preserved = $c->groupBy(fn ($r) => $r->unknown_prop ?? 'unknown', true);
    /** @psalm-check-type-exact $_preserved = Collection<array-key, Collection<string, Customer>&static>&static */
}

/** @param Collection<int, Customer> $c */
function mixed_key_by_key_resolves_to_array_key(Collection $c): void
{
    $_keyed = $c->keyBy(fn ($r): mixed => $r->unknown_prop ?? 'unknown');
    /** @psalm-check-type-exact $_keyed = Collection<array-key, Customer>&static */
}

/** @param Collection<int, Customer> $c */
function typed_callback_keeps_its_key_type(Collection $c): void
{
    $_grouped = $c->groupBy(fn (Customer $r): string => $r->getTable());
    /** @psalm-check-type-exact $_grouped = Collection<string, Collection<int, Customer>&static>&static */

    $_keyed = $c->keyBy(fn (Customer $r): string => $r->getTable());
    /** @psalm-check-type-exact $_keyed = Collection<string, Customer>&static */
}

function fixed_template_subclass_defers(Issue1884FixedCollection $c): void
{
    $_keyed = $c->keyBy(fn (Customer $r): mixed => $r->unknown_prop ?? 'unknown');
    /** @psalm-check-type-exact $_keyed = Issue1884FixedCollection */
}
?>
--EXPECTF--
