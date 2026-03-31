<?php

declare(strict_types=1);

namespace App\Models;

use App\Builders\PostBuilder;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
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
