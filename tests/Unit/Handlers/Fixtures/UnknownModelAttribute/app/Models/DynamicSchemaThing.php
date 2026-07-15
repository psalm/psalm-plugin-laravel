<?php

declare(strict_types=1);

namespace KnownAttributeFixture\Models;

use Illuminate\Database\Eloquent\Model;

/** Fixture whose migration creates a real column through a name the schema parser cannot resolve. */
class DynamicSchemaThing extends Model
{
    /** @var list<string> */
    protected $guarded = [];

    protected $table = 'dynamic_schema_things';

    public static function createWithDynamicMigrationColumn(): self
    {
        return static::create(['real_col' => 'valid at runtime']);
    }
}
