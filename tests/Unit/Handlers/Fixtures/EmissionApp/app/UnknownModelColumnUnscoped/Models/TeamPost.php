<?php

declare(strict_types=1);

namespace UnscopedColumnFixture\Models;

use Illuminate\Database\Eloquent\Model;

final class TeamPost extends Model
{
    use BelongsToTeam;

    protected $table = 'posts';
}
