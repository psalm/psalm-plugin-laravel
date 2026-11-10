<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Enums\SerializedIntStatus;

/**
 * An int-backed enum reaching `$connection` as a plain PROPERTY DEFAULT, with no `#[Connection]` involved —
 * the half no attribute mirror could ever see.
 * `newInstanceWithoutConstructor()` applies it before any initializer runs.
 *
 * Same failure if unnormalized: `getConnectionName()`'s `enum_value()` yields the raw `int` backing value,
 * which the registry's `?string $connection` rejects outside every section guard, dropping the whole model.
 *
 * Live on every supported line, not only 13.x: `$connection` is `\UnitEnum|string|null` and `getConnectionName()`
 * applies `enum_value()` since 12.28, well before the 13.0 `#[Connection]` attribute.
 *
 * @internal fixture used by ModelMetadataRegistryTest
 */
final class PlainEnumConnectionModel extends Model
{
    /** @var \Tests\Psalm\LaravelPlugin\Unit\Fixtures\Enums\SerializedIntStatus */
    protected $connection = SerializedIntStatus::Published;
}
