<?php

declare(strict_types=1);

namespace ColumnListFixture\Models;

use Illuminate\Database\Eloquent\Model;

#[AuditedAttribute]
class AuditedPost extends Model
{
    protected $table = 'posts';
}
