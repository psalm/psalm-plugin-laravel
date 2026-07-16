--FILE--
<?php declare(strict_types=1);

use App\Models\Contract;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Guards the registry relations() OWN-CLASS invariant.
 *
 * documentParts() is a plain-typed (non-generic) HasMany declared on the abstract PARENT
 * (AbstractDocument); Contract inherits it. RelationMethodParser is own-class, so the inherited
 * relation is NOT in Contract's relations() map — the property handler cannot pin the related model
 * via the registry, and the non-generic return type also blocks its Tier 1 generic extraction, so it
 * falls back to the imprecise Collection<int, Model> bound.
 *
 * If relations() were ever changed to ancestor-walk (as scopes()/accessors() deliberately do),
 * Contract would resolve the precise Collection<int, Part> and this assertion would flip — catching
 * the regression. The own-class split is otherwise invisible (it preserves the pre-registry parser
 * behaviour, which was itself own-class).
 */

function inherited_relation_stays_own_class(Contract $contract): Collection
{
    /** @psalm-check-type-exact $parts = Collection<int, Model> */
    $parts = $contract->documentParts;

    return $parts;
}

?>
--EXPECTF--
