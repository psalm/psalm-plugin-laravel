<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Enums\SerializedIntStatus;

/**
 * An int-backed enum reaching `$connection` as a plain PROPERTY DEFAULT, with no `#[Connection]` involved —
 * the other half of what {@see EnumConnectionModel} covers, and the half no attribute mirror could ever see.
 * `newInstanceWithoutConstructor()` applies it before any initializer runs.
 *
 * Same failure if unnormalized: `getConnectionName()`'s `enum_value()` yields the raw `int` backing value,
 * which the registry's `?string $connection` rejects outside every section guard, dropping the whole model.
 *
 * Laravel 12.28+, NOT 13.0: `$connection` widened to `\UnitEnum|string|null` and `getConnectionName()` began
 * applying `enum_value()` in 12.28, well before the 13.0 `#[Connection]` attribute. So this row is live on
 * most of the 12.x line, and its consuming test gates on the capability (an int back from the getter) rather
 * than on any attribute's existence. On 12.14–12.27 the getter returns the enum object untouched.
 *
 * @internal fixture used by ModelMetadataRegistryTest
 */
final class PlainEnumConnectionModel extends Model
{
    /** @var \Tests\Psalm\LaravelPlugin\Unit\Fixtures\Enums\SerializedIntStatus */
    protected $connection = SerializedIntStatus::Published;
}
