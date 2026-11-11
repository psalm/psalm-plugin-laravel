<?php

declare(strict_types=1);

namespace PsalterFixture\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Plain model: the constructor is Eloquent's own, so `Post::make()` is rewritable.
 */
class Post extends Model
{
    /** @var list<string> */
    protected $fillable = ['title'];
}
