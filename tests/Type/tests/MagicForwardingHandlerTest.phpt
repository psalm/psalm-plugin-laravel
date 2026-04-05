--FILE--
<?php declare(strict_types=1);

use App\Models\Role;
use App\Models\User;
use App\Models\Phone;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Tests for MethodForwardingHandler: verifies that method calls on Eloquent Relations
 * preserve the Relation's generic type for fluent methods and pass through for terminals.
 */

// Path 1: Builder method via @mixin (where is in Builder's declaring_method_ids)
function test_where_preserves_relation_type(): void {
    /** @var HasOne<Phone, User> $r */
    $r = (new User())->phone();
    $_ = $r->where('active', true);
    /** @psalm-check-type-exact $_ = HasOne<Phone, User> */
}

// Path 2: QueryBuilder-only method via __call
function test_orderBy_preserves_relation_type(): void {
    /** @var HasOne<Phone, User> $r */
    $r = (new User())->phone();
    $_ = $r->orderBy('name');
    /** @psalm-check-type-exact $_ = HasOne<Phone, User> */
}

// Chained call across both paths
function test_chain_preserves_relation_type(): void {
    /** @var HasOne<Phone, User> $r */
    $r = (new User())->phone();
    $_ = $r->where('active', true)->orderBy('name');
    /** @psalm-check-type-exact $_ = HasOne<Phone, User> */
}

// Non-fluent: get() from Relation stub returns Collection (not mixin-dependent)
function test_get_returns_collection(): void {
    /** @var HasOne<Phone, User> $r */
    $r = (new User())->phone();
    $_ = $r->get();
    /** @psalm-check-type-exact $_ = \Illuminate\Database\Eloquent\Collection<int, Phone> */
}

// Mixin-only method NOT on Relation stubs
function test_mixin_only_preserves_relation_type(): void {
    /** @var HasOne<Phone, User> $r */
    $r = (new User())->phone();
    $_ = $r->withoutGlobalScopes();
    /** @psalm-check-type-exact $_ = HasOne<Phone, User> */
}

// Different Relation subclass: BelongsToMany (verifies template params work beyond HasOne)
function test_belongsToMany_where_preserves_relation_type(): void {
    /** @var BelongsToMany<Role, User> $r */
    $r = (new User())->roles();
    $_ = $r->where('active', true);
    /** @psalm-check-type-exact $_ = BelongsToMany<Role, User> */
}

function test_belongsToMany_orderBy_preserves_relation_type(): void {
    /** @var BelongsToMany<Role, User> $r */
    $r = (new User())->roles();
    $_ = $r->orderBy('name');
    /** @psalm-check-type-exact $_ = BelongsToMany<Role, User> */
}

// Non-fluent: first() resolved via Relation stub (workaround for Psalm mixin template bug)
function test_first_returns_model_or_null(): void {
    /** @var HasOne<Phone, User> $r */
    $r = (new User())->phone();
    $_ = $r->first();
    /** @psalm-check-type-exact $_ = Phone|null */
}

// Terminal after chain: where()+orderBy() preserve HasOne, first() resolves via stub
function test_terminal_after_chain(): void {
    /** @var HasOne<Phone, User> $r */
    $r = (new User())->phone();
    $_ = $r->where('active', true)->orderBy('name')->first();
    /** @psalm-check-type-exact $_ = Phone|null */
}
?>
--EXPECTF--
