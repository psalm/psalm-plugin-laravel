<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

final class Tag extends Model {
    protected $table = 'tags';

    /**
     * Get all of the posts that are assigned this tag.
     * @psalm-return MorphToMany<Post>
     */
    public function posts(): MorphToMany
    {
        return $this->morphedByMany(Post::class, 'taggable');
    }

    /**
     * Get all of the videos that are assigned this tag.
     * @psalm-return MorphToMany<Video>
     */
    public function videos(): MorphToMany
    {
        return $this->morphedByMany(Video::class, 'taggable');
    }
}
