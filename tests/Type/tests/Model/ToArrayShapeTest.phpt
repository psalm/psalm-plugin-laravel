--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox;

use App\Models\SerializableModel;
use Carbon\CarbonInterface;

/**
 * Real serialized shape for attributesToArray()/toArray(), driven by $appends.
 *
 * The harness runs no migrations, so SerializableModel has no schema and its shape comes entirely from
 * $appends — asserting the handler's real (non-mixed) output end-to-end. The appends pin each
 * HasAttributes::mutateAttributeForArray() rule (scalar / collection / date, modern vs legacy, hidden);
 * see the SerializableModel method docblocks. The shape is OPEN (...<string, mixed>) with every key
 * optional: query-dependent keys and partial loads keep unknown keys at mixed, not an offset error.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/923
 *
 * @return array<string, mixed>
 */
function test_to_array_infers_the_appended_shape(SerializableModel $model): array
{
    /** @psalm-check-type-exact $shape = array{badge_number?: int, full_name?: string, permissions?: array<int, array<array-key, mixed>>, published_at?: string, registered_at?: CarbonInterface, roles?: array<int, string>, tags?: array<array-key, mixed>, ...<string, mixed>} */
    $shape = $model->toArray();

    return $shape;
}

/**
 * @return array<string, mixed>
 */
function test_attributes_to_array_infers_the_appended_shape(SerializableModel $model): array
{
    /** @psalm-check-type-exact $shape = array{badge_number?: int, full_name?: string, permissions?: array<int, array<array-key, mixed>>, published_at?: string, registered_at?: CarbonInterface, roles?: array<int, string>, tags?: array<array-key, mixed>, ...<string, mixed>} */
    $shape = $model->attributesToArray();

    return $shape;
}
?>
--EXPECTF--
