--FILE--
<?php declare(strict_types=1);

// A non-App\Models namespace on purpose: these fixtures share the default-config scan with the real
// tests/Application app models, and `App\Models\*` names (e.g. PrivateScopeModel) would collide.
namespace Tests\Psalm\LaravelPlugin\Sandbox;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PublicScopesModel extends Model
{
    // Reported as PublicModelScope: a public #[Scope] (its static call is a runtime fatal).
    #[Scope]
    public function active(Builder $query): Builder
    {
        return $query;
    }

    // Reported as PublicModelAccessor: a public legacy scopeXxx().
    public function scopePublished(Builder $query): Builder
    {
        return $query;
    }

    // Reported as PublicModelAccessor: a public legacy accessor.
    public function getTitleAttribute(): string
    {
        return 'Untitled';
    }

    // Reported as PublicModelAccessor: a public legacy mutator.
    public function setTitleAttribute(string $value): void
    {
        $this->attributes['title'] = $value;
    }

    // Clean: a protected #[Scope] (the correct convention).
    #[Scope]
    protected function draft(Builder $query): Builder
    {
        return $query;
    }

    // Clean: a protected accessor.
    protected function getSlugAttribute(): string
    {
        return 'slug';
    }

    // Clean: an ordinary public method, not a scope or accessor.
    public function publish(): void
    {
    }

    // Clean: a public method that only shares the `scope` prefix.
    public function scoped(): void
    {
    }
}

// Clean: a private scope is a separate dead-code concern, not a visibility convention one.
class PrivateScopeFixtureModel extends Model
{
    private function scopeSecret(Builder $query): Builder
    {
        return $query;
    }
}

// A public legacy scope on a trait is reported once (PublicModelAccessor), at the trait's own declaration.
trait HasArchivedScope
{
    public function scopeArchived(Builder $query): Builder
    {
        return $query;
    }
}

class TraitScopeModel extends Model
{
    use HasArchivedScope;
}

// A second model composing the same trait does NOT add a second report (deduped on the shared storage).
class SecondTraitScopeModel extends Model
{
    use HasArchivedScope;
}

// A public legacy scope on an ABSTRACT base is reported at the base's own declaration...
abstract class AbstractBaseModel extends Model
{
    public function scopeOnBase(Builder $query): Builder
    {
        return $query;
    }
}

// ...and the concrete child that inherits it is not reported again.
class ConcreteChildModel extends AbstractBaseModel
{
}

// Public visibility forced by a contract is not reported (it cannot be narrowed to protected). The guard
// keys off overridden_method_ids, which Psalm fills identically for parent classes, implemented interfaces,
// and abstract trait requirements; the two cases below cover that path (an interface implementation behaves
// the same but is omitted because the strict test config trips unrelated MissingAbstractPureAnnotation
// noise on the interface declaration).

// An override of a public parent scope.
class OverridingChildModel extends PublicScopesModel
{
    #[\Override]
    public function scopePublished(Builder $query): Builder
    {
        return $query;
    }
}

// A scope required by an abstract trait method.
trait RequiresPublishedScope
{
    abstract public function scopeRequiredPublished(Builder $query): Builder;
}

class AbstractTraitRequiredModel extends Model
{
    use RequiresPublishedScope;

    #[\Override]
    public function scopeRequiredPublished(Builder $query): Builder
    {
        return $query;
    }
}

// Clean: a non-Model class with scope/accessor-shaped methods is never flagged.
class NotAModel
{
    public function scopePublished(): void
    {
    }

    public function getTitleAttribute(): string
    {
        return 'x';
    }
}
?>
--EXPECTF--
PublicModelScope on line %d: Eloquent #[Scope] method active() should be protected, not public: called statically (Model::active()) it is a runtime fatal.
PublicModelAccessor on line %d: Eloquent query scope scopePublished() should be protected, not public; it is dispatched through the query builder, never by name.
PublicModelAccessor on line %d: Eloquent accessor/mutator getTitleAttribute() should be protected, not public; it is dispatched via __get()/__set(), never by name.
PublicModelAccessor on line %d: Eloquent accessor/mutator setTitleAttribute() should be protected, not public; it is dispatched via __get()/__set(), never by name.
PublicModelAccessor on line %d: Eloquent query scope scopeArchived() should be protected, not public; it is dispatched through the query builder, never by name.
PublicModelAccessor on line %d: Eloquent query scope scopeOnBase() should be protected, not public; it is dispatched through the query builder, never by name.
