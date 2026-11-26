<?php

declare(strict_types=1);

namespace ColumnListFixture\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use KnownAttributeFixture\Models\Tag;

class ConstrainedCountPost extends Model
{
    use HasConstrainedCount;

    protected $table = 'posts';

    /** @return BelongsToMany<Tag, $this> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'post_tag', 'post_id', 'tag_id');
    }
}
