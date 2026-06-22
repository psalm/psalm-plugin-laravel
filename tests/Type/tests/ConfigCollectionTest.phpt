--FILE--
<?php declare(strict_types=1);

use Illuminate\Config\Repository;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Config;

/**
 * Repository::collection()/Config::collection()/config()->collection() narrow
 * to Collection<keyType, valueType> reflected from the booted config — the same
 * resolution as get(), wrapped in a Collection. `auth.defaults` is a stable
 * keyed array (`['guard' => ..., 'passwords' => ...]`) present in every
 * supported Laravel version (12-13). Values are generalized to `string`
 * (env-driven); structural keys are kept.
 *
 * The facade assertions also guard the @method param synthesis: without it,
 * registering a return-type provider for the facade pseudo-method crashes Psalm
 * (same crash class as #454/#854). The scalar-default case additionally pins the
 * tightened `\Closure|array|null` default that synthesizeCollectionParams() sets.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1150
 */

function repo_collection(Repository $config): void
{
    $_result = $config->collection('auth.defaults');
    /** @psalm-check-type-exact $_result = Collection<'guard'|'passwords', string> */
}

function facade_collection(): void
{
    $_result = Config::collection('auth.defaults');
    /** @psalm-check-type-exact $_result = Collection<'guard'|'passwords', string> */
}

function helper_chained_collection(): void
{
    $_result = config()->collection('auth.defaults');
    /** @psalm-check-type-exact $_result = Collection<'guard'|'passwords', string> */
}

function collection_scalar_value_defers_to_stub(Repository $config): void
{
    // `app.name` is a string, not an array — collection() would throw at runtime,
    // so the handler declines and the stub's generic type wins.
    $_result = $config->collection('app.name');
    /** @psalm-check-type-exact $_result = Collection<array-key, mixed> */
}

function facade_collection_rejects_scalar_default(): void
{
    // The synthesized facade params tighten $default to \Closure|array|null, so a
    // scalar default is rejected exactly as on the concrete Repository — it would
    // throw InvalidArgumentException at runtime when the key is absent.
    Config::collection('auth.defaults', 'fallback');
}
?>
--EXPECTF--
InvalidArgument on line %d: Argument 2 of Illuminate\Support\Facades\Config::collection expects %s, but 'fallback' provided
