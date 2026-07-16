<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Connection;
use Illuminate\Database\Eloquent\Model;
use Tests\Psalm\LaravelPlugin\Unit\Fixtures\Enums\SerializedIntStatus;

/**
 * `#[Connection]` with an int-backed enum: `getConnectionName()` applies `enum_value()`, which unwraps a
 * BackedEnum to its raw `->value` — an `int` here, despite the method's `@return string|null`. The registry's
 * `?string $connection` rejects that under `strict_types`, and the TypeError lands outside every section
 * guard, so the whole model entry would be dropped.
 * {@see \Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadataRegistryBuilder::asConnectionName()}
 * coerces it at the read site — the single expression whose value reaches that field, and therefore the only
 * place a model that overrides `getConnectionName()` itself can also be caught. Laravel 13.0+ only.
 *
 * @internal fixture used by ModelMetadataRegistryTest
 */
#[Connection(SerializedIntStatus::Published)]
final class EnumConnectionModel extends Model {}
