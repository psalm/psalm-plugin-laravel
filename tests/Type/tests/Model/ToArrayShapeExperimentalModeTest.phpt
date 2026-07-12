--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm-experimental-issues.xml
--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox;

use App\Models\SerializableModel;
use Carbon\CarbonInterface;

/**
 * Model serialization shape inference is stable: experimental issue enforcement
 * must not gate the ModelToArrayShapeHandler registration.
 *
 * @return array<string, mixed>
 */
function test_to_array_shape_stays_active_with_experimental_enforcement(SerializableModel $model): array
{
    /** @psalm-check-type-exact $shape = array{badge_number?: int, full_name?: string, permissions?: array<int, array<array-key, mixed>>, published_at?: string, registered_at?: CarbonInterface, roles?: array<int, string>, tags?: array<array-key, mixed>, ...<string, mixed>} */
    $shape = $model->toArray();

    return $shape;
}
?>
--EXPECTF--
