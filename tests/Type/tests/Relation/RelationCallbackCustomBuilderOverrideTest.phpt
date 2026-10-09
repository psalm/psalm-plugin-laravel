--FILE--
<?php declare(strict_types=1);

use App\Models\Part;
use App\Models\WrappedModel;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A model's custom builder may override whereHas() with its own callback contract (WrappedModel's builder hands
 * the callback the relation name too). Static `Model::whereHas()` and relation-forwarded calls dispatch through
 * the base Eloquent Builder, so the override guard must run against the model's EFFECTIVE builder class.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1676
 */

final class WrapperOwner
{
    /** @return HasMany<WrappedModel, \Illuminate\Database\Eloquent\Model> */
    public function children(): HasMany
    {
        throw new \LogicException();
    }
}

function test_static_call_keeps_the_custom_builder_contract(): void
{
    WrappedModel::whereHas('parts', function (Builder $q, string $label): void {
        $q->where('label', $label);
    });
}

function test_relation_forwarded_call_keeps_the_custom_builder_contract(WrapperOwner $owner): void
{
    $owner->children()->whereHas('parts', function (Builder $q, string $label): void {
        $q->where('label', $label);
    });
}

function test_query_call_keeps_the_custom_builder_contract(): void
{
    WrappedModel::query()->whereHas('parts', function (Builder $q, string $label): void {
        $q->where('label', $label);
    });
}

/** A model without an overriding builder is still typed through the same receivers. */
function test_plain_model_static_call_is_typed(): void
{
    Part::whereHas('supplier', function ($q): void {
        /** @psalm-check-type-exact $q = Builder<App\Models\Supplier> */
        $q->where('id', 1);
    });
}
?>
--EXPECTF--
