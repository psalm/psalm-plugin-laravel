--FILE--
<?php declare(strict_types=1);

use Illuminate\Database\Eloquent\Builder;
use App\Models\User;

// Issue #216: Model::select() forward call should return Builder<Model>
// These Query\Builder methods are forwarded through Eloquent\Builder::__call()
// and must preserve the Builder<TModel> generic context.

// --- Static calls on Model (the core issue #216) ---

/** @return Builder<User> */
function test_select_static(): Builder
{
    return User::select('name', 'email');
}

/** @return Builder<User> */
function test_select_array_static(): Builder
{
    return User::select(['name', 'email']);
}

/** @return Builder<User> */
function test_orderBy_static(): Builder
{
    return User::orderBy('name');
}

/** @return Builder<User> */
function test_whereIn_static(): Builder
{
    return User::whereIn('id', [1, 2, 3]);
}

/** @return Builder<User> */
function test_limit_static(): Builder
{
    return User::limit(10);
}

/** @return Builder<User> */
function test_join_static(): Builder
{
    return User::join('posts', 'users.id', '=', 'posts.user_id');
}

/** @return Builder<User> */
function test_whereNull_static(): Builder
{
    return User::whereNull('deleted_at');
}

/** @return Builder<User> */
function test_groupBy_static(): Builder
{
    return User::groupBy('department');
}

/** @return Builder<User> */
function test_distinct_static(): Builder
{
    return User::distinct();
}

// --- Instance calls via query() ---

/** @return Builder<User> */
function test_select_instance(): Builder
{
    return User::query()->select('name', 'email');
}

/** @return Builder<User> */
function test_addSelect_instance(): Builder
{
    return User::query()->addSelect('name');
}

/** @return Builder<User> */
function test_distinct_instance(): Builder
{
    return User::query()->distinct();
}

/** @return Builder<User> */
function test_orderByDesc_instance(): Builder
{
    return User::query()->orderByDesc('name');
}

/** @return Builder<User> */
function test_groupBy_instance(): Builder
{
    return User::query()->groupBy('department');
}

/** @return Builder<User> */
function test_limit_instance(): Builder
{
    return User::query()->limit(10);
}

/** @return Builder<User> */
function test_take_instance(): Builder
{
    return User::query()->take(10);
}

/** @return Builder<User> */
function test_offset_instance(): Builder
{
    return User::query()->offset(5);
}

/** @return Builder<User> */
function test_skip_instance(): Builder
{
    return User::query()->skip(5);
}

/** @return Builder<User> */
function test_whereIn_instance(): Builder
{
    return User::query()->whereIn('id', [1, 2, 3]);
}

/** @return Builder<User> */
function test_whereNotIn_instance(): Builder
{
    return User::query()->whereNotIn('id', [1, 2, 3]);
}

/** @return Builder<User> */
function test_whereBetween_instance(): Builder
{
    return User::query()->whereBetween('id', [1, 100]);
}

/** @return Builder<User> */
function test_whereNull_instance(): Builder
{
    return User::query()->whereNull('deleted_at');
}

/** @return Builder<User> */
function test_whereNotNull_instance(): Builder
{
    return User::query()->whereNotNull('email');
}

/** @return Builder<User> */
function test_whereColumn_instance(): Builder
{
    return User::query()->whereColumn('first_name', 'last_name');
}

/** @return Builder<User> */
function test_join_instance(): Builder
{
    return User::query()->join('posts', 'users.id', '=', 'posts.user_id');
}

/** @return Builder<User> */
function test_leftJoin_instance(): Builder
{
    return User::query()->leftJoin('posts', 'users.id', '=', 'posts.user_id');
}

/** @return Builder<User> */
function test_lockForUpdate_instance(): Builder
{
    return User::query()->lockForUpdate();
}

/** @return Builder<User> */
function test_whereExists_instance(): Builder
{
    return User::query()->whereExists(function (\Illuminate\Database\Query\Builder $query) {
        $query->select('id')->where('active', true);
    });
}

// --- Chaining: static call + forwarded methods + terminal ---

function test_select_chain_to_first(): ?User
{
    return User::select('name', 'email')->first();
}

/** @return Builder<User> */
function test_chained_query_builder_methods(): Builder
{
    return User::select('name', 'email')
        ->where('active', true)
        ->orderBy('name')
        ->limit(10)
        ->offset(5);
}

/** @return Builder<User> */
function test_chained_instance_methods(): Builder
{
    return User::query()
        ->select('name')
        ->whereIn('id', [1, 2])
        ->orderByDesc('name')
        ->groupBy('department')
        ->take(10)
        ->skip(5);
}
?>
--EXPECTF--
