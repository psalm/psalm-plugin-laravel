<?php

declare(strict_types=1);

namespace RelationDefaultsFixture\Models;

use Illuminate\Database\Eloquent\Model;

abstract class AbstractDefaultsModel extends Model
{
    protected $with = ['childRelation'];
}
