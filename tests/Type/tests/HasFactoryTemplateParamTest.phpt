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

// Explicit binding wins over ModelFactoryMethodTypeProvider's default — the
// handler returns null when @use HasFactory<X> is set, so the stub's @return
// TFactory resolves to the user's Factory subclass (more precise than the
// plugin's Factory<Model>). Guards the documented escape hatch from #960.
$_explicitFactory = ExplicitArticle::factory();
/** @psalm-check-type-exact $_explicitFactory = ExplicitArticleFactory */;
?>
--EXPECTF--
