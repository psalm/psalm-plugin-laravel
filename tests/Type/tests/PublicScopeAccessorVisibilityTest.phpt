--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm-protected-scope-visibility.xml
--FILE--
<?php declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class PublicScopesModel extends Model
{
    // Reported: public legacy scopeXxx()
    public function scopePublished(Builder $query): Builder
    {
        return $query;
    }

    // Reported: public #[Scope]-attributed scope
    #[Scope]
    public function active(Builder $query): Builder
    {
        return $query;
    }

    // Reported: public legacy accessor
    public function getTitleAttribute(): string
    {
        return 'Untitled';
    }

    // Reported: public legacy mutator
    public function setTitleAttribute(string $value): void
    {
        $this->attributes['title'] = $value;
    }

    // Clean: protected legacy scope (the convention the rule enforces)
    protected function scopeDraft(Builder $query): Builder
    {
        return $query;
    }

    // Clean: protected accessor
    protected function getSlugAttribute(): string
    {
        return 'slug';
    }

    // Clean: ordinary public method, not a scope/accessor
    public function publish(): void
    {
    }

    // Clean: framework method whose name only shares the `scope` prefix
    public function scoped(): void
    {
    }
}

// Clean: private scope is a separate concern — the rule flags PUBLIC only
class PrivateScopeModel extends Model
{
    private function scopeSecret(Builder $query): Builder
    {
        return $query;
    }
}

// A public scope hosted on a trait must be reported at the trait declaration
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

// Clean: a non-Model class with scope/accessor-shaped methods is never flagged
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

// A second model composing the same trait must NOT add a second report: the trait scope dedupes to one
// diagnostic at the trait's own declaration, regardless of how many models use it.
class SecondTraitScopeModel extends Model
{
    use HasArchivedScope;
}

// A public scope on an ABSTRACT base model is still reported, at the base's own declaration...
abstract class AbstractBaseModel extends Model
{
    public function scopeOnBase(Builder $query): Builder
    {
        return $query;
    }
}

// ...and the concrete child that inherits it must NOT be reported again (the scope appears on the base).
class ConcreteChildModel extends AbstractBaseModel
{
}
?>
--EXPECTF--
PublicModelScope on line %d: Eloquent query scope scopePublished() should be protected, not public. Scopes are dispatched through the query builder and never called by name, so public only widens the model API (and a public #[Scope] called statically is a runtime fatal).
PublicModelScope on line %d: Eloquent query scope active() should be protected, not public. Scopes are dispatched through the query builder and never called by name, so public only widens the model API (and a public #[Scope] called statically is a runtime fatal).
PublicModelAccessor on line %d: Eloquent attribute accessor/mutator getTitleAttribute() should be protected, not public. Accessors and mutators are dispatched via __get() / __set() magic and never called by name, so public only widens the model API.
PublicModelAccessor on line %d: Eloquent attribute accessor/mutator setTitleAttribute() should be protected, not public. Accessors and mutators are dispatched via __get() / __set() magic and never called by name, so public only widens the model API.
PublicModelScope on line %d: Eloquent query scope scopeArchived() should be protected, not public. Scopes are dispatched through the query builder and never called by name, so public only widens the model API (and a public #[Scope] called statically is a runtime fatal).
PublicModelScope on line %d: Eloquent query scope scopeOnBase() should be protected, not public. Scopes are dispatched through the query builder and never called by name, so public only widens the model API (and a public #[Scope] called statically is a runtime fatal).
