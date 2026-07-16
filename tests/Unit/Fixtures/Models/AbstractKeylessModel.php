<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Fixtures\Models;

use Illuminate\Database\Eloquent\Model;

/** @internal fixture used by ModelMetadataRegistryTest */
abstract class AbstractKeylessModel extends Model
{
    protected $primaryKey;

    /** @var bool */
    public $incrementing = false;
}
