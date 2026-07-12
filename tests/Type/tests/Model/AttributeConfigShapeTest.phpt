--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
// Eloquent class-config attributes (#[Appends]/#[Hidden]/…) are Laravel 13.0+. Feature-detect rather
// than version-pin so the gate self-corrects across the 12.4–13.x support range.
if (!class_exists(\Illuminate\Database\Eloquent\Attributes\Appends::class)) {
    echo 'skip needs Laravel >= 13.0 (Eloquent class-config attributes)';
}
--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox;

use App\Models\AttributeSerializableModel;

/**
 * #[Appends]/#[Hidden] PHP-attribute config feeds the serialized shape end-to-end.
 *
 * newInstanceWithoutConstructor() skips Laravel's initializers, so the registry replays the attribute
 * config at warm-up (applyClassAttributeConfig). The harness runs no migrations, so the shape comes
 * entirely from #[Appends]: `full_name` (accessor-backed) is present, while `secret_token` is appended
 * but dropped by #[Hidden]. Without the fix getAppends() would miss #[Appends] and the shape would
 * collapse to the loose array<string, mixed> stub.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/923
 *
 * @return array<string, mixed>
 */
function test_attribute_config_shapes_the_serialized_array(AttributeSerializableModel $model): array
{
    /** @psalm-check-type-exact $shape = array{full_name?: string, ...<string, mixed>} */
    $shape = $model->toArray();

    return $shape;
}
?>
--EXPECTF--
