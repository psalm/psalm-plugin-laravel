<?php

declare(strict_types=1);

namespace App\Models;

use App\Collections\TagCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Tag model using the $collectionClass property to declare a custom collection.
 * Tests the third detection pattern (alongside #[CollectedBy] and newCollection() override).
 */
final class Tag extends Model
{
    protected $table = 'tags';

    /** @var class-string<TagCollection<array-key, static>> */
    protected static string $collectionClass = TagCollection::class;

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
