<?php

declare(strict_types=1);

namespace RelationDefaultsFixture\Models;

use Illuminate\Database\Eloquent\Model;

final class BadDefaultsModel extends Model
{
    protected $with = ['misspelledRelation'];

    protected $withCount = ['misspelledCount'];
}
