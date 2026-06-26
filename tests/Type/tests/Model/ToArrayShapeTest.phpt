--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox;

use App\Models\SerializableModel;
use Carbon\CarbonInterface;

/**
 * Real serialized shape for attributesToArray()/toArray(), driven by $appends.
 *
 * The type-test harness runs no migrations, so a model's columns are unknowable here. SerializableModel
 * therefore carries no schema, and its serialized surface comes entirely from $appends — which Laravel
 * always serializes — so this asserts the handler's real (non-mixed) output end-to-end through Psalm.
 * It also pins HasAttributes::mutateAttributeForArray()'s serialization-divergence rules:
 *  - full_name:     legacy string accessor, kept as string.
 *  - badge_number:  modern Attribute::get(fn(): int) accessor, kept as int.
 *  - roles:         legacy Collection<int, string> -> array<int, string> (generic keys/values kept).
 *  - tags:          modern bare Collection -> array<array-key, mixed> (value type unknown).
 *  - permissions:   modern Collection<int, Collection<int, string>> -> array<int, array<array-key, mixed>> (one-level collapse).
 *  - published_at:  modern date accessor -> serialized to a string.
 *  - registered_at: legacy date accessor -> not date-serialized, kept as Carbon.
 *  - secret_token:  appended but also in $hidden, so it is dropped (hidden wins).
 *
 * The shape is OPEN (...<string, mixed>) and every key optional: query-dependent keys (aggregate /
 * selectRaw aliases, setAttribute, relations) and partial column loads keep unknown keys at mixed
 * rather than a false-positive offset error.
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
