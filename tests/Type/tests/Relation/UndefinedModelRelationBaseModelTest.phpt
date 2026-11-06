--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm-with-optin-custom-issues.xml
--FILE--
<?php declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\Relation;

/**
 * A concrete base model's relation set lives in its subclasses, so a name that some userland
 * descendant defines is not a typo on the base type. A name no descendant defines stays reported.
 * See https://github.com/psalm/psalm-plugin-laravel/issues/2208
 */

// Concrete (non-abstract) userland base, like BookStack\App\Model.
class Rel2208Base extends Model
{
}

// The subclass chain that owns the relations: abstract middle, then a leaf below it.
abstract class Rel2208Entity extends Rel2208Base
{
    /** @return BelongsTo<Rel2208Leaf, $this> */
    public function ownedBy(): BelongsTo
    {
        return $this->belongsTo(Rel2208Leaf::class);
    }
}

class Rel2208Page extends Rel2208Entity
{
    /** @return BelongsTo<Rel2208Leaf, $this> */
    public function pageOnly(): BelongsTo
    {
        return $this->belongsTo(Rel2208Leaf::class);
    }
}

// Three levels below the base: the relation lives on the deepest descendant.
final class Rel2208DeepPage extends Rel2208Page
{
    /** @return BelongsTo<Rel2208Leaf, $this> */
    public function deepOnly(): BelongsTo
    {
        return $this->belongsTo(Rel2208Leaf::class);
    }
}

// A base whose descendants never define 'typoOnLonelyBase'.
class Rel2208Lonely extends Model
{
}

final class Rel2208LonelyChild extends Rel2208Lonely
{
}

// A second branch below the same base, defining a name the first branch lacks.
final class Rel2208LonelyOther extends Rel2208Lonely
{
    /** @return BelongsTo<Rel2208Leaf, $this> */
    public function otherOnly(): BelongsTo
    {
        return $this->belongsTo(Rel2208Leaf::class);
    }
}

// Not a subclass: a @mixin host is listed as a dependent of its target but never runs as a model.
/** @mixin Rel2208Lonely */
final class Rel2208MixinHost
{
    public function typoViaMixin(): void
    {
    }
}

// A leaf with no descendants.
final class Rel2208Leaf extends Model
{
    /** @return BelongsTo<Rel2208Base, $this> */
    public function base(): BelongsTo
    {
        return $this->belongsTo(Rel2208Base::class);
    }
}

// Negative: the receiver is the concrete base type, the relation lives on a descendant.
function base_instance(Rel2208Base $model): void
{
    $model->load(['ownedBy', 'pageOnly']);
    $model->load('deepOnly');
    $model->load('ownedBy');
    $model->loadMissing('ownedBy');
    $model->loadCount('ownedBy');
    $model->loadCount('ownedBy as owned_total');
    $model->with('ownedBy');
}

function base_callable(callable $format): void
{
    $format(fn (Rel2208Base $model) => $model->load(['ownedBy']));
}

Rel2208Base::with('ownedBy');
Rel2208Base::with(['ownedBy', 'pageOnly']);
Rel2208Base::query()->with('ownedBy');
Rel2208Base::query()->without('ownedBy');
Rel2208Base::query()->has('ownedBy');
Rel2208Base::query()->whereHas('ownedBy');
Rel2208Base::query()->whereRelation('ownedBy', 'id', 1);
Rel2208Base::query()->withWhereHas('ownedBy');

// Dot notation through a relation that returns the base model: the intermediate is declined too.
Rel2208Leaf::with('base.ownedBy');
Rel2208Leaf::with('base.ownedBy.base.pageOnly');

// Positive: a base whose descendants never define the name is still reported.
function base_typo(Rel2208Base $model, Rel2208Lonely $lonely): void
{
    $lonely->load('otherOnly');
    $lonely->load('typoViaMixin');
    $model->load('typoOnBase');
    $lonely->load('typoOnLonelyBase');
    Rel2208Base::query()->with('typoStatic');
    Rel2208Base::with('typoStaticBase');
}

// Positive: a descendant-defined name is not valid on a sibling branch (the leaf has no descendants).
function leaf_typo(Rel2208Leaf $leaf): void
{
    $leaf->load('ownedBy');
    $leaf->load('typoOnLeaf');
    Rel2208Leaf::with('base.typoAfterBase');
}

// Positive: a descendant defining the name must not silence the sibling subclass that lacks it.
function sibling_typo(Rel2208Page $page, Rel2208LonelyChild $child): void
{
    $child->load('otherOnly');
    $page->load('pageOnly');
    $page->load('typoOnPage');
}
?>
--EXPECTF--
UndefinedModelRelation on line %d: Relation 'typoViaMixin' is not defined on Rel2208Lonely.
UndefinedModelRelation on line %d: Relation 'typoOnBase' is not defined on Rel2208Base.
UndefinedModelRelation on line %d: Relation 'typoOnLonelyBase' is not defined on Rel2208Lonely.
UndefinedModelRelation on line %d: Relation 'typoStatic' is not defined on Rel2208Base.
UndefinedModelRelation on line %d: Relation 'typoStaticBase' is not defined on Rel2208Base.
UndefinedModelRelation on line %d: Relation 'ownedBy' is not defined on Rel2208Leaf.
UndefinedModelRelation on line %d: Relation 'typoOnLeaf' is not defined on Rel2208Leaf.
UndefinedModelRelation on line %d: Relation 'typoAfterBase' is not defined on Rel2208Base.
UndefinedModelRelation on line %d: Relation 'otherOnly' is not defined on Rel2208LonelyChild.
UndefinedModelRelation on line %d: Relation 'typoOnPage' is not defined on Rel2208Page.
