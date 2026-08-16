<?php

declare(strict_types=1);

namespace RelationDefaultsFixture\Models;

use Illuminate\Database\Eloquent\Model;

final class NumericKeyedDefaultsModel extends Model
{
    protected $with = [2 => 'numericMissing'];
}
