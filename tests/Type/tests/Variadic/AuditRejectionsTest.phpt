--FILE--
<?php declare(strict_types=1);

namespace App;

use Illuminate\Support\LazyCollection;
use Illuminate\Support\Stringable;

/**
 * Regression guard for methods that look variadic (their Laravel source uses
 * func_get_args()) but whose extras are silently discarded by a fixed-arity
 * downstream call. These must NOT be annotated with @psalm-variadic — the
 * analogue to #809's rejection of Builder::find.
 *
 * If any of these assertions stops firing, a contributor has probably added a
 * misleading @psalm-variadic annotation. Re-audit the method against its
 * Laravel source before removing the guard.
 */

/** @param LazyCollection<int, string> $c */
function audit_lazy_merge_rejected(LazyCollection $c): void
{
    $c->merge([], []);
}

/** @param LazyCollection<int, string> $c */
function audit_lazy_diff_rejected(LazyCollection $c): void
{
    $c->diff([], []);
}

/** @param LazyCollection<int, string> $c */
function audit_lazy_intersect_rejected(LazyCollection $c): void
{
    $c->intersect([], []);
}

/** @param LazyCollection<int, string> $c */
function audit_lazy_union_rejected(LazyCollection $c): void
{
    $c->union([], []);
}

function audit_stringable_trim_rejected(Stringable $s): void
{
    $s->trim(', ', 'x', 'y');
}

function audit_stringable_ltrim_rejected(Stringable $s): void
{
    $s->ltrim(', ', 'x', 'y');
}

function audit_stringable_rtrim_rejected(Stringable $s): void
{
    $s->rtrim(', ', 'x', 'y');
}
?>
--EXPECTF--
TooManyArguments on line %d: Too many arguments for method Illuminate\Support\LazyCollection::merge - saw 2
TooManyArguments on line %d: Too many arguments for method Illuminate\Support\LazyCollection::diff - saw 2
TooManyArguments on line %d: Too many arguments for method Illuminate\Support\LazyCollection::intersect - saw 2
TooManyArguments on line %d: Too many arguments for method Illuminate\Support\LazyCollection::union - saw 2
TooManyArguments on line %d: Too many arguments for method Illuminate\Support\Stringable::trim - saw 3
TooManyArguments on line %d: Too many arguments for method Illuminate\Support\Stringable::ltrim - saw 3
TooManyArguments on line %d: Too many arguments for method Illuminate\Support\Stringable::rtrim - saw 3
