<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/** @internal fixture used by ModelMetadataRegistryTest on Laravel 13+ */
#[Table(key: 'attribute_id', keyType: 'int', incrementing: false)]
final class TableIdentifierPropertiesWinModel extends Model
{
    /** @var string */
    protected $primaryKey = 'property_id';

    /** @var string */
    protected $keyType = 'string';
}
