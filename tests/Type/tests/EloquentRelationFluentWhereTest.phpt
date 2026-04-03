--FILE--
<?php declare(strict_types=1);

use App\Models\Phone;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Verify that calling Builder methods (where, orderBy, etc.) on a Relation
 * returns the Relation type, not Builder.
 *
 * Before the fix, @mixin Builder on Relation caused Psalm to resolve these
 * methods on Builder, bypassing RelationsMethodHandler entirely.
 */

// HasOne: basic fluent methods
function test_hasOne_where(HasOne $rel): HasOne {
    return $rel->where('active', true);
}

function test_hasOne_orderBy(HasOne $rel): HasOne {
    return $rel->orderBy('name');
}

function test_hasOne_limit(HasOne $rel): HasOne {
    return $rel->limit(10);
}

function test_hasOne_chain(HasOne $rel): HasOne {
    return $rel->where('active', true)->orderBy('name')->limit(10);
}

// HasMany: common pattern
function test_hasMany_where(HasMany $rel): HasMany {
    return $rel->where('role', 'student');
}

function test_hasMany_whereIn(HasMany $rel): HasMany {
    return $rel->whereIn('status', ['active', 'pending']);
}

function test_hasMany_whereNull(HasMany $rel): HasMany {
    return $rel->whereNull('deleted_at');
}

// BelongsToMany: multi-template-param relation
function test_belongsToMany_where(BelongsToMany $rel): BelongsToMany {
    return $rel->where('active', true);
}

// Real-world pattern: calling where() on a relation returned by a model method
function test_model_relation_where(): HasOne {
    return (new User())->phone()->where('active', true);
}

function test_model_relation_belongsToMany(): BelongsToMany {
    return (new User())->roles()->where('name', 'admin');
}

function test_model_relation_fluent_chain(): HasOne {
    return (new User())->phone()->where('active', true)->orderBy('created_at')->latest();
}

// Exact type check with explicitly typed relation variable
function test_exact_type_preserved(): HasOne {
    /** @var HasOne<Phone, User> $rel */
    $rel = (new User())->phone();
    $filtered = $rel->where('active', true);
    /** @psalm-check-type-exact $filtered = HasOne<Phone, User> */
    return $filtered;
}

// Non-fluent methods must NOT return the relation type.
// first() should return TRelatedModel|null, not HasOne.
function test_first_returns_model_not_relation(HasOne $rel): ?\Illuminate\Database\Eloquent\Model {
    return $rel->where('active', true)->first();
}
?>
--EXPECTF--
