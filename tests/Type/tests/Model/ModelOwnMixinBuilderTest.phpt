--FILE--
<?php declare(strict_types=1);

namespace ModelOwnMixinBuilder;

use App\Builders\InheritedModelBuilder;
use App\Models\IdeHelperAnnotatedModel;
use Eloquent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * #2297: a model's own `@mixin` (laravel-ide-helper writes `@mixin \Eloquent` on every model) replaced the
 * `@mixin Builder<static>` it inherits from the Model stub, so builder calls failed. Psalm 7.5 merges inherited
 * mixins below a class's own ones (vimeo/psalm#13671) and binds `static` against the host, not an earlier
 * matching mixin (vimeo/psalm#13670). `static` must resolve to the called model, never to Model.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/2297
 */

/**
 * The `models --write` shape, including its builder-or-model pseudo static methods.
 *
 * @method static Builder<static>|FqEloquentMixinModel whereId($value)
 * @method static Builder<static>|FqEloquentMixinModel withTrashed(bool $withTrashed = true)
 * @mixin \Eloquent
 */
final class FqEloquentMixinModel extends Model
{
    use SoftDeletes;
}

/**
 * ide-helper imports the alias when it does not write fully qualified names.
 *
 * @mixin Eloquent
 */
final class ImportedEloquentMixinModel extends Model {}

/** @mixin \Illuminate\Database\Eloquent\Model */
final class ModelMixinModel extends Model {}

final class HelperMixin
{
    public function marker(): string
    {
        return 'marker';
    }

    /** Clashes with Builder::first(); the model's own mixin wins. */
    public function first(): string
    {
        return 'first';
    }
}

/**
 * The `models --write-mixin` shape: a helper class carrying its own members.
 *
 * @mixin HelperMixin
 */
final class HelperMixinModel extends Model {}

/** @template T */
final class GenericHelper
{
    /** @return T|null */
    public function first(): mixed
    {
        return null;
    }
}

/** @mixin GenericHelper<static> */
final class GenericHelperModel extends Model {}

final class LeafHelper
{
    public function first(): string
    {
        return 'leaf';
    }
}

/** @mixin LeafHelper */
final class OuterHelper {}

/** @mixin OuterHelper */
final class TransitiveHelperModel extends Model {}

/** @mixin \Eloquent */
abstract class AbstractMixinModel extends Model
{
    public static function findFirst(): ?static
    {
        return static::find(1);
    }
}

final class ConcreteMixinChild extends AbstractMixinModel {}

/**
 * The manual workaround from #2297 keeps working unchanged.
 *
 * @mixin \Eloquent
 * @mixin Builder<static>
 */
final class ManualWorkaroundModel extends Model {}

function eloquent_alias_mixin(): void
{
    $_static = FqEloquentMixinModel::where('id', 1);
    /** @psalm-check-type-exact $_static = Builder<FqEloquentMixinModel> */

    $_instance = (new FqEloquentMixinModel())->where('id', 1);
    /** @psalm-check-type-exact $_instance = Builder<FqEloquentMixinModel> */

    $_find = FqEloquentMixinModel::find(1);
    /** @psalm-check-type-exact $_find = FqEloquentMixinModel|null */

    $_instanceFind = (new FqEloquentMixinModel())->find(1);
    /** @psalm-check-type-exact $_instanceFind = FqEloquentMixinModel|null */

    $_pseudo = FqEloquentMixinModel::whereId(1)->first();
    /** @psalm-check-type-exact $_pseudo = FqEloquentMixinModel|null */

    $_trashed = FqEloquentMixinModel::withTrashed()->where('id', 1);
    /** @psalm-check-type-exact $_trashed = Builder<FqEloquentMixinModel> */

    $_imported = ImportedEloquentMixinModel::where('id', 1);
    /** @psalm-check-type-exact $_imported = Builder<ImportedEloquentMixinModel> */
}

function model_mixin(): void
{
    $_static = ModelMixinModel::where('id', 1);
    /** @psalm-check-type-exact $_static = Builder<ModelMixinModel> */

    $_instanceFind = (new ModelMixinModel())->find(1);
    /** @psalm-check-type-exact $_instanceFind = ModelMixinModel|null */
}

function helper_mixin(): void
{
    $_where = HelperMixinModel::where('id', 1);
    /** @psalm-check-type-exact $_where = Builder<HelperMixinModel> */

    $_marker = (new HelperMixinModel())->marker();
    /** @psalm-check-type-exact $_marker = string */

    $_staticFirst = HelperMixinModel::first();
    /** @psalm-check-type-exact $_staticFirst = string */

    $_instanceFirst = (new HelperMixinModel())->first();
    /** @psalm-check-type-exact $_instanceFirst = string */
}

/** A generic own mixin keeps its own binding next to the inherited Builder<static>. */
function generic_helper_mixin(): void
{
    $_first = GenericHelperModel::first();
    /** @psalm-check-type-exact $_first = GenericHelperModel|null */

    $_instanceFirst = (new GenericHelperModel())->first();
    /** @psalm-check-type-exact $_instanceFirst = GenericHelperModel|null */

    $_where = GenericHelperModel::where('id', 1);
    /** @psalm-check-type-exact $_where = Builder<GenericHelperModel> */
}

function transitive_helper_mixin(): void
{
    $_first = (new TransitiveHelperModel())->first();
    /** @psalm-check-type-exact $_first = string */

    $_where = TransitiveHelperModel::where('id', 1);
    /** @psalm-check-type-exact $_where = Builder<TransitiveHelperModel> */
}

function inherited_mixin(): void
{
    $_find = ConcreteMixinChild::find(1);
    /** @psalm-check-type-exact $_find = ConcreteMixinChild|null */

    $_instance = (new ConcreteMixinChild())->where('id', 1);
    /** @psalm-check-type-exact $_instance = Builder<ConcreteMixinChild> */

    $_lateStatic = ConcreteMixinChild::findFirst();
    /** @psalm-check-type-exact $_lateStatic = ConcreteMixinChild|null */
}

function unchanged_receivers(): void
{
    $_manual = ManualWorkaroundModel::where('id', 1);
    /** @psalm-check-type-exact $_manual = Builder<ManualWorkaroundModel> */

    $_model = Model::where('id', 1);
    /** @psalm-check-type-exact $_model = Builder<Model> */
}

/** Registered model: the custom builder from `newEloquentBuilder()` and the Query\Builder hop resolve. */
function registered_custom_builder(): void
{
    $_static = IdeHelperAnnotatedModel::where('id', 1);
    /** @psalm-check-type-exact $_static = InheritedModelBuilder<IdeHelperAnnotatedModel> */

    $_instance = (new IdeHelperAnnotatedModel())->where('id', 1);
    /** @psalm-check-type-exact $_instance = InheritedModelBuilder<IdeHelperAnnotatedModel> */

    $_find = IdeHelperAnnotatedModel::find(1);
    /** @psalm-check-type-exact $_find = IdeHelperAnnotatedModel|null */

    $_queryHop = IdeHelperAnnotatedModel::orderBy('id')->get();
    /** @psalm-check-type-exact $_queryHop = Collection<int, IdeHelperAnnotatedModel> */
}

function undefined_methods_still_reported(): void
{
    FqEloquentMixinModel::undefinedBuilderMethod();
    (new HelperMixinModel())->undefinedBuilderMethod();
}
?>
--EXPECTF--
UndefinedMagicMethod on line %d: Magic method ModelOwnMixinBuilder\FqEloquentMixinModel::undefinedbuildermethod does not exist
UndefinedMagicMethod on line %d: Magic method ModelOwnMixinBuilder\HelperMixinModel::undefinedbuildermethod does not exist
