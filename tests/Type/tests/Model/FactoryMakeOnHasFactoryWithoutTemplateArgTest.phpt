--FILE--
<?php declare(strict_types=1);

namespace App\Sandbox;

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Standard Laravel scaffold: `use HasFactory;` without the template arg.
 * `php artisan make:model -f` produces this shape, so it covers the common
 * case where downstream `Model::factory()->count(N)->make()` chains break
 * without plugin intervention.
 *
 * Tracked by ModelFactoryMethodTypeProvider (Option A) and the
 * FactoryCountTypeProvider Model fallback (Option B).
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/960
 */
class Bookshelf extends Model
{
    use HasFactory;
}

/**
 * @extends Factory<Bookshelf>
 */
final class BookshelfFactory extends Factory
{
    /** @var class-string<Bookshelf> */
    protected $model = Bookshelf::class;

    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }
}

// ----- Option A: factory() returns Factory<Bookshelf> even without `@use HasFactory<...>` -----
$_factory = Bookshelf::factory();
/** @psalm-check-type-exact $_factory = \Illuminate\Database\Eloquent\Factories\Factory<\App\Sandbox\Bookshelf> */;

// ----- Option A: count(N)->make() chains through to Collection<int, Bookshelf> -----
$_shelves = Bookshelf::factory()->count(10)->make();
/** @psalm-check-type-exact $_shelves = \Illuminate\Database\Eloquent\Collection<int, \App\Sandbox\Bookshelf> */;

// ----- Option A: bare make() (no count) returns the single model -----
$_single = Bookshelf::factory()->make();
/** @psalm-check-type-exact $_single = \App\Sandbox\Bookshelf */;

// ----- foreach over the count(N) result must not trigger PossibleRawObjectIteration -----
foreach (Bookshelf::factory()->count(10)->make() as $_shelf) {
    /** @psalm-check-type-exact $_shelf = \App\Sandbox\Bookshelf */;
}

// ----- create() chain mirrors make() -----
$_created = Bookshelf::factory()->count(3)->create();
/** @psalm-check-type-exact $_created = \Illuminate\Database\Eloquent\Collection<int, \App\Sandbox\Bookshelf> */;

// ----- LSB: HasFactory inherited via abstract base, still resolves to subclass -----
abstract class Entity extends Model
{
    use HasFactory;
}

class Page extends Entity
{
}

$_pageFactory = Page::factory();
/** @psalm-check-type-exact $_pageFactory = \Illuminate\Database\Eloquent\Factories\Factory<\App\Sandbox\Page> */;

$_pages = Page::factory()->count(3)->make();
/** @psalm-check-type-exact $_pages = \Illuminate\Database\Eloquent\Collection<int, \App\Sandbox\Page> */;


/**
 * @extends Factory<CustomMethodModel>
 */
final class CustomMethodFactory extends Factory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }
}

final class CustomMethodModel extends Model
{
    use HasFactory;

    protected static function newFactory(): CustomMethodFactory
    {
        return CustomMethodFactory::new();
    }
}

$_customMethodFactory = CustomMethodModel::factory();
/** @psalm-check-type-exact $_customMethodFactory = \App\Sandbox\CustomMethodFactory */;

final class DocblockCustomMethodModel extends Model
{
    use HasFactory;

    /** @return CustomMethodFactory */
    protected static function newFactory()
    {
        return CustomMethodFactory::new();
    }
}

$_docblockCustomMethodFactory = DocblockCustomMethodModel::factory();
/** @psalm-check-type-exact $_docblockCustomMethodFactory = \App\Sandbox\CustomMethodFactory */;

final class NullCustomMethodModel extends Model
{
    use HasFactory;

    protected static function newFactory(): null
    {
        return null;
    }
}

$_nullCustomMethodFactory = NullCustomMethodModel::factory();
/** @psalm-check-type-exact $_nullCustomMethodFactory = Factory<\App\Sandbox\NullCustomMethodModel> */;

final class NonFactoryCustomMethodModel extends Model
{
    use HasFactory;

    protected static function newFactory(): \stdClass
    {
        return new \stdClass();
    }
}

$_nonFactoryCustomMethodFactory = NonFactoryCustomMethodModel::factory();
/** @psalm-check-type-exact $_nonFactoryCustomMethodFactory = Factory<\App\Sandbox\NonFactoryCustomMethodModel> */;

final class UnscannedCustomMethodModel extends Model
{
    use HasFactory;

    /**
     * @return NeverScannedFactory
     * @psalm-suppress UndefinedDocblockClass
     */
    protected static function newFactory()
    {
        throw new \RuntimeException();
    }
}

$_unscannedCustomMethodFactory = UnscannedCustomMethodModel::factory();
/** @psalm-check-type-exact $_unscannedCustomMethodFactory = Factory<\App\Sandbox\UnscannedCustomMethodModel> */;

/**
 * @extends Factory<StaticPropertyModel>
 */
final class StaticPropertyFactory extends Factory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }
}

final class StaticPropertyModel extends Model
{
    use HasFactory;

    /** @var class-string<StaticPropertyFactory> */
    protected static $factory = StaticPropertyFactory::class;
}

$_staticPropertyFactory = StaticPropertyModel::factory();
/** @psalm-check-type-exact $_staticPropertyFactory = \App\Sandbox\StaticPropertyFactory */;

/**
 * @extends Factory<AttributedModel>
 */
final class AttributedFactory extends Factory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }
}

/** @psalm-suppress InvalidArgument Laravel's attribute accepts this concrete Factory subclass. */
#[UseFactory(AttributedFactory::class)]
final class AttributedModel extends Model
{
    use HasFactory;
}

$_attributedFactory = AttributedModel::factory();
/** @psalm-check-type-exact $_attributedFactory = \App\Sandbox\AttributedFactory */;

final class AmbiguousCustomMethodModel extends Model
{
    use HasFactory;

    /** @var class-string<StaticPropertyFactory> */
    protected static $factory = StaticPropertyFactory::class;

    protected static function newFactory(): CustomMethodFactory|StaticPropertyFactory
    {
        return CustomMethodFactory::new();
    }
}

$_ambiguousCustomMethodFactory = AmbiguousCustomMethodModel::factory();
/** @psalm-check-type-exact $_ambiguousCustomMethodFactory = Factory<\App\Sandbox\AmbiguousCustomMethodModel> */;

final class NonFactoryPropertyModel extends Model
{
    use HasFactory;

    /** @var class-string<\stdClass> */
    protected static $factory = \stdClass::class;
}

$_nonFactoryProperty = NonFactoryPropertyModel::factory();
/** @psalm-check-type-exact $_nonFactoryProperty = Factory<\App\Sandbox\NonFactoryPropertyModel> */;

final class UnboundFactory extends Factory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }
}

final class UnboundFactoryModel extends Model
{
    use HasFactory;

    /** @var class-string<UnboundFactory> */
    protected static $factory = UnboundFactory::class;
}

$_unboundFactory = UnboundFactoryModel::factory();
/** @psalm-check-type-exact $_unboundFactory = Factory<\App\Sandbox\UnboundFactoryModel> */;

/**
 * @extends Factory<InheritedStaticPropertyModel>
 */
final class InheritedStaticPropertyFactory extends Factory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }
}

abstract class InheritedStaticPropertyBase extends Model
{
    use HasFactory;

    /** @var class-string<InheritedStaticPropertyFactory> */
    protected static $factory = InheritedStaticPropertyFactory::class;
}

final class InheritedStaticPropertyModel extends InheritedStaticPropertyBase
{
}

$_inheritedStaticPropertyFactory = InheritedStaticPropertyModel::factory();
/** @psalm-check-type-exact $_inheritedStaticPropertyFactory = \App\Sandbox\InheritedStaticPropertyFactory */;

/**
 * @extends Factory<LiteralStaticPropertyModel>
 */
final class LiteralStaticPropertyFactory extends Factory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }
}

final class LiteralStaticPropertyModel extends Model
{
    use HasFactory;

    /** @psalm-suppress MissingPropertyType */
    protected static $factory = LiteralStaticPropertyFactory::class;
}

$_literalStaticPropertyFactory = LiteralStaticPropertyModel::factory();
/** @psalm-check-type-exact $_literalStaticPropertyFactory = \App\Sandbox\LiteralStaticPropertyFactory */;

/**
 * @extends Factory<SignatureReturnModel>
 */
final class SignatureReturnFactory extends Factory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }
}

final class SignatureReturnModel extends Model
{
    use HasFactory;

    /**
     * @return Factory
     * @psalm-suppress MismatchingDocblockReturnType
     */
    protected static function newFactory(): SignatureReturnFactory
    {
        return SignatureReturnFactory::new();
    }
}

$_signatureReturnFactory = SignatureReturnModel::factory();
/** @psalm-check-type-exact $_signatureReturnFactory = \App\Sandbox\SignatureReturnFactory */;

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory as ConventionHasFactory;
use Illuminate\Database\Eloquent\Model as ConventionModelBase;

final class ConventionModel extends ConventionModelBase
{
    use ConventionHasFactory;
}

namespace Database\Factories;

use App\Models\ConventionModel;
use Illuminate\Database\Eloquent\Factories\Factory as LaravelFactory;

/**
 * @extends LaravelFactory<ConventionModel>
 */
final class ConventionModelFactory extends LaravelFactory
{
    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }
}

namespace App\Models;

$_conventionFactory = ConventionModel::factory();
/** @psalm-check-type-exact $_conventionFactory = \Database\Factories\ConventionModelFactory */;
?>
--EXPECTF--
