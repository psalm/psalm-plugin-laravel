<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * `#[Table]`'s primary-key sub-overrides — the half of the attribute that is not the table name.
 * `initializeModelAttributes()` applies each behind a still-at-its-default guard (`primaryKey === 'id'`,
 * `keyType === 'int'`), so this fixture leaves all three at their defaults for the attribute to speak.
 *
 * Laravel 13.0+ (`#[Table]`); the consuming test is gated on `class_exists()`.
 *
 * @internal fixture used by ModelMetadataRegistryTest
 */
#[Table(name: 'table_key_attribute_models', key: 'uuid', keyType: 'string', incrementing: false)]
final class TableKeyAttributeModel extends Model {}
