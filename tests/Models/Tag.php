<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

final class Tag extends Model {
    protected $table = 'tags';


}
