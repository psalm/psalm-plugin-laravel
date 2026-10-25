<?php

declare(strict_types=1);

namespace RelationDefaultsFixture\Models;

use Illuminate\Database\Eloquent\Model;

final class KeyedDefaultsModel extends Model
{
    /** @var array<string, string> */
    protected $with = ['missingRelation' => 'ignored'];
}
