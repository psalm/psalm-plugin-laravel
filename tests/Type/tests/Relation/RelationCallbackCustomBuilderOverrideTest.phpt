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

/**
 * A builder that is not the model's own: the model's overriding builder is never involved in these calls.
 *
 * @template T of \Illuminate\Database\Eloquent\Model
 * @extends Builder<T>
 */
final class ReportingBuilder extends Builder {}

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

/**
 * A direct custom-builder receiver runs on that builder, not on the model's default one, so the model's overriding
 * builder does not matter and the callback is typed.
 *
 * @param ReportingBuilder<WrappedModel> $builder
 */
function test_direct_builder_receiver_ignores_the_models_own_builder(ReportingBuilder $builder): void
{
    $builder->whereHas('parts', function ($q): void {
        /** @psalm-check-type-exact $q = Builder<Part> */
        $q->where('id', 1);
    });
}
?>
--EXPECTF--
