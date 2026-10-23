--FILE--
<?php declare(strict_types=1);

// The MorphTo model type is declared on an autoloadable app model so its return provider is
// registered; the inline PHPT models are deliberately not enough to exercise that path.

use App\Contracts\HasTitle;
use App\Models\Invoice;
use App\Models\MorphIntersectionOwner;
use App\Models\MorphIntersectionOwnerChild;
use App\Models\Shop;
use App\Models\WorkOrder;
use Illuminate\Database\Eloquent\Model;

function test_morph_to_intersection_get_related(MorphIntersectionOwner $owner): string
{
    $related = $owner->titleable()->getRelated();
    /** @psalm-check-type-exact $related = Model&HasTitle */
    return $related->title();
}

function test_morph_to_intersection_property(MorphIntersectionOwner $owner): ?string
{
    $related = $owner->titleable;
    /** @psalm-check-type-exact $related = Model&HasTitle|null */
    return $related?->title();
}

function test_morph_to_intersection_trait_alias_get_related(MorphIntersectionOwner $owner): string
{
    $related = $owner->titleableThroughTrait()->getRelated();
    /** @psalm-check-type-exact $related = Model&HasTitle */
    return $related->title();
}

function test_trait_this_declaring_model_getParent(MorphIntersectionOwner $owner): MorphIntersectionOwner
{
    $parent = $owner->titleableThroughTrait()->getParent();
    /** @psalm-check-type-exact $parent = MorphIntersectionOwner&static */
    return $parent;
}

function test_inherited_self_declaring_model_stays_base(MorphIntersectionOwnerChild $owner): MorphIntersectionOwner
{
    $parent = $owner->titleable()->getParent();
    /** @psalm-check-type-exact $parent = MorphIntersectionOwner */
    return $parent;
}

function test_inherited_trait_this_binds_to_child(MorphIntersectionOwnerChild $owner): MorphIntersectionOwnerChild
{
    $parent = $owner->titleableThroughTrait()->getParent();
    /** @psalm-check-type-exact $parent = MorphIntersectionOwnerChild&static */
    return $parent;
}

function test_morph_to_without_docblock_uses_native_model_fallback(Shop $shop): string
{
    $related = $shop->shopable()->getRelated();
    /** @psalm-check-type-exact $related = Model */
    return $related->getKeyName();
}

function test_non_morph_relation_generic_is_preserved(WorkOrder $workOrder): Invoice
{
    return $workOrder->invoice()->getRelated();
}

?>
--EXPECTF--
