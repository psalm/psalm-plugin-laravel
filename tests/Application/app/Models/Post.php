<?php

declare(strict_types=1);

namespace App\Models;

use App\Builders\PostBuilder;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Post model with a custom query builder via #[UseEloquentBuilder] attribute (Laravel 12+).
 *
 * @see https://laravel-news.com/defining-a-dedicated-query-builder-in-laravel-12-with-php-attributes
 */
#[UseEloquentBuilder(PostBuilder::class)]
final class Post extends Model
{
    protected $table = 'posts';

    /**
     * Legacy scope: called as Post::query()->featured() or Post::featured().
     * Exercises the scope + custom builder return type path.
     *
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeFeatured($query)
    {
        return $query->where('featured', true);
    }

    /**
     * @psalm-return HasMany<Comment>
     */
    public function comments(): HasMany
    {
        return $this->hasMany(Comment::class);
    }

    /**
     * @psalm-return MorphOne<Image>
     */
    public function image(): MorphOne
    {
        return $this->morphOne(Image::class, 'imageable');
    }

    /**
     * @psalm-return \Illuminate\Database\Eloquent\Relations\MorphToMany<Tag>
     */
    public function tags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }
}
