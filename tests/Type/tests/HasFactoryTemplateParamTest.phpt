--FILE--
<?php declare(strict_types=1);

use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Standard Laravel pattern: use HasFactory without specifying the template.
 * Laravel resolves the factory class via naming convention at runtime.
 * The plugin should suppress MissingTemplateParam for this common case.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/517
 */
class Article extends Model
{
    use HasFactory;
}

/**
 * Explicit template binding still works — users who want type-safe
 * factory() calls can opt in with @use HasFactory<ConcreteFactory>.
 *
 * @extends Factory<ExplicitArticle>
 */
class ExplicitArticleFactory extends Factory
{
    protected $model = ExplicitArticle::class;

    /** @return array<string, mixed> */
    #[\Override]
    public function definition(): array
    {
        return [];
    }
}

class ExplicitArticle extends Model
{
    /** @use HasFactory<ExplicitArticleFactory> */
    use HasFactory;
}

// Explicit binding wins over ModelFactoryMethodTypeProvider's discovery: the
// user's Factory subclass named by @use HasFactory<X> is returned (more precise
// than the plugin's Factory<Model>), re-typed with the count `factory()` sets
// (`<null>` here). Guards the documented escape hatch from #960.
$_explicitFactory = ExplicitArticle::factory();
/** @psalm-check-type-exact $_explicitFactory = ExplicitArticleFactory<null> */;

class BareFactoryArticle extends Model
{
    /** @use HasFactory<Factory> */
    use HasFactory;
}

$_bareFactory = BareFactoryArticle::factory();
/** @psalm-check-type-exact $_bareFactory = Factory */;

abstract class BareFactoryArticleBase extends Model
{
    /** @use HasFactory<Factory> */
    use HasFactory;
}

final class InheritedBareFactoryArticle extends BareFactoryArticleBase
{
}

$_inheritedBareFactory = InheritedBareFactoryArticle::factory();
/** @psalm-check-type-exact $_inheritedBareFactory = Factory */;

trait ApplicationHasFactory
{
    /** @use HasFactory<ExplicitArticleFactory> */
    use HasFactory;
}

class WrappedExplicitArticle extends Model
{
    use ApplicationHasFactory;
}

$_wrappedExplicitFactory = WrappedExplicitArticle::factory();
/** @psalm-check-type-exact $_wrappedExplicitFactory = ExplicitArticleFactory<null> */;

trait NestedApplicationHasFactory
{
    use ApplicationHasFactory;
}

class NestedWrappedExplicitArticle extends Model
{
    use NestedApplicationHasFactory;
}

$_nestedWrappedExplicitFactory = NestedWrappedExplicitArticle::factory();
/** @psalm-check-type-exact $_nestedWrappedExplicitFactory = ExplicitArticleFactory<null> */;
?>
--EXPECTF--
