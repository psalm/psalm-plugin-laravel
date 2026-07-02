<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Connection;
use Illuminate\Database\Eloquent\Model;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Enums\SerializedIntStatus;

/**
 * `#[Connection]` with an int-backed enum: `getConnectionName()` would return the raw `int`, which the
 * registry's `?string $connection` field rejects under `strict_types` — `applyConnectionAttribute()`
 * must normalize it to a string so the whole model entry is not dropped. Laravel 13.0+ only.
 *
 * @internal fixture used by ModelMetadataRegistryTest
 */
#[Connection(SerializedIntStatus::Published)]
final class EnumConnectionModel extends Model {}
