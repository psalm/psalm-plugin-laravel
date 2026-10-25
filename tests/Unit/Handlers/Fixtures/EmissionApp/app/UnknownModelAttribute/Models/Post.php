<?php

declare(strict_types=1);

namespace KnownAttributeFixture\Models;

use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

#[UseEloquentBuilder(PostBuilder::class)]
class Post extends Model
{
    protected $table = 'posts';

    /** @var list<string> */
    protected $fillable = ['author_id', 'title', 'body'];

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class)->withPivot('pivot_col');
    }
}
