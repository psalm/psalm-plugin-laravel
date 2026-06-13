--FILE--
<?php declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

// The PublicModelScope / PublicModelAccessor rules are enabled by default. This test runs under the
// default tests/Type/psalm.xml (no --ARGS-- config override), so a public scope and a public accessor
// must each be reported without any opt-in. This is the gate that the rule is on by default, not just
// under the dedicated config used by PublicScopeAccessorVisibilityTest.phpt.
class DefaultConfigScopeModel extends Model
{
    public function scopePublished(Builder $query): Builder
    {
        return $query;
    }

    public function getTitleAttribute(): string
    {
        return 'Untitled';
    }
}
?>
--EXPECTF--
PublicModelScope on line %d: Eloquent query scope scopePublished() should be protected, not public. Scopes are dispatched through the query builder and never called by name, so public only widens the model API (and a public #[Scope] called statically is a runtime fatal).
PublicModelAccessor on line %d: Eloquent attribute accessor/mutator getTitleAttribute() should be protected, not public. Accessors and mutators are dispatched via __get() / __set() magic and never called by name, so public only widens the model API.
