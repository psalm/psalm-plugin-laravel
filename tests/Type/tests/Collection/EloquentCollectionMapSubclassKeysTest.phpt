--FILE--
<?php declare(strict_types=1);

use App\Models\Part;
use App\Models\Vehicle;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Collection as SupportCollection;

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

/**
 * The key is a compound type over the class's own template: it cannot be resolved without substituting the
 * receiver's arguments, so the handler declines instead of leaking `key-of<Data:KeyOfCollection>`.
 *
 * @template Data of array<array-key, mixed>
 * @extends Collection<key-of<Data>, Part>
 */
final class KeyOfCollection extends Collection {}

/**
 * A `key-of<>` over a concrete operand expands to a literal key, which map() must keep.
 *
 * @extends Collection<key-of<array{first: int}>, Part>
 */
final class ConcreteKeyOfCollection extends Collection {}

/** @extends Collection<ConstantKeyCollection::KEY, Part> */
final class ConstantKeyCollection extends Collection
{
    public const KEY = 'fixed';
}

/**
 * Forwards calls to a collection through @mixin, but returns whatever its __call() builds: a plain
 * Support\Collection, so Eloquent-only methods must not be promised on a map result.
 *
 * @mixin Collection<int, Part>
 */
final class CollectionProxy
{
    /** @param array<array-key, mixed> $args */
    public function __call(string $_method, array $args): SupportCollection
    {
        return new SupportCollection($args);
    }
}

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

/** @return Collection<'first', Part> */
function concrete_key_of_is_expanded(ConcreteKeyOfCollection $parts): Collection
{
    return $parts->map(static fn (Part $p): Part => $p);
}

/** @return Collection<'fixed', Part> */
function constant_key_is_expanded(ConstantKeyCollection $parts): Collection
{
    return $parts->map(static fn (Part $p): Part => $p);
}

/**
 * @template Input of array<array-key, mixed>
 * @param KeyOfCollection<Input> $items
 * @return SupportCollection<key-of<Input>, Part>
 */
function compound_template_key_declines(KeyOfCollection $items): SupportCollection
{
    return $items->map(static fn (Part $p): Part => $p);
}

function mixin_receiver_declines(CollectionProxy $proxy): void
{
    $_keyed = $proxy->mapWithKeys(static fn (Part $p): array => ['key' => $p]);
    /** @psalm-check-type-exact $_keyed = SupportCollection<'key', Part> */

    $_mapped = $proxy->map(static fn (Part $p): Part => $p);
    /** @psalm-check-type-exact $_mapped = SupportCollection<int, Part> */
}
?>
--EXPECTF--
