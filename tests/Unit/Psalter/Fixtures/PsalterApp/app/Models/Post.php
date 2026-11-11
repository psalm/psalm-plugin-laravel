<?php

declare(strict_types=1);

namespace PsalterFixture\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Plain model: stock Eloquent, so `Post::where()` is rewritable.
 */
class Post extends Model
{
    /** @var list<string> */
    protected $fillable = ['title'];
}
