--FILE--
<?php declare(strict_types=1);

use App\Models\Part;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Collection;

/**
 * A subclass with no template parameters of its own still gets the model-preserving map (#1699).
 *
 * @extends Collection<int, Part>
 */
final class Parts extends Collection {}

/**
 * Fixes the key to string: map() keeps those keys, whatever the receiver's own template says.
 *
 * @template TContext
 * @template TModel of Part
 * @extends Collection<string, TModel>
 */
final class ContextCollection extends Collection {}

/**
 * Binds Eloquent's TKey to int and TModel to Part, ignoring both of its own templates.
 *
 * @template TA
 * @template TB
 * @extends Collection<int, Part>
 */
final class MetadataCollection extends Collection {}

/** @return Collection<int, Part> */
function identity_map_on_non_generic_subclass(Parts $parts): Collection
{
    $_same = $parts->map(static fn (Part $p): Part => $p);
    /** @psalm-check-type-exact $_same = Collection<int, Part> */

    return $_same;
}

/** @param ContextCollection<int, Part> $items */
function fixed_string_key_survives(ContextCollection $items): void
{
    $_vehicles = $items->map(static fn (Part $_p): Vehicle => new Vehicle());
    /** @psalm-check-type-exact $_vehicles = Collection<string, Vehicle> */
}

/** @param MetadataCollection<string, bool> $items */
function reordered_templates_use_the_bound_key(MetadataCollection $items): void
{
    $_same = $items->map(static fn (Part $p): Part => $p);
    /** @psalm-check-type-exact $_same = Collection<int, Part> */
}
?>
--EXPECTF--
