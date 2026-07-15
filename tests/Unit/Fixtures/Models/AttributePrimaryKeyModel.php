<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/** @internal fixture used by ModelMetadataRegistryTest */
#[Table(key: 'external_id', keyType: 'string', incrementing: false)]
final class AttributePrimaryKeyModel extends Model {}
