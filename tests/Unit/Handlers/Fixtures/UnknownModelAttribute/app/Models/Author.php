<?php

declare(strict_types=1);

namespace KnownAttributeFixture\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Parent side of the HasMany relation exercised by the creation-path (#1679) usage file. */
class Author extends Model
{
    protected $table = 'authors';

    /** @var list<string> */
    protected $fillable = ['name'];

    /** @return HasMany<Post, $this> */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /** @return HasMany<VirtualTag, $this> */
    public function virtualTags(): HasMany
    {
        return $this->hasMany(VirtualTag::class);
    }

    /** `$this->rel()` inside the declaring model resolves `HasMany<Post, $this>`. */
    public function addPostWithTypo(): Post
    {
        return $this->posts()->create(['typo_this_relation' => 'x', 'body' => 'y']);
    }
}
