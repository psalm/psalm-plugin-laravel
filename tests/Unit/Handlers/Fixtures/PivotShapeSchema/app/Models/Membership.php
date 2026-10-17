<?php

declare(strict_types=1);

namespace PivotShapeFixture\Models;

use Illuminate\Database\Eloquent\Model;

/** Passed as the table argument of belongsToMany(): its schema types the pivot columns. */
final class Membership extends Model {}
