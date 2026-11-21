<?php

declare(strict_types=1);

namespace ColumnListFixture\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * @property int $computed_total a select alias the app documents
 */
class DocumentedPost extends Model
{
    protected $table = 'posts';
}
