<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphOne;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Test model for non-generic relationship accessor resolution (#497).
 *
 * All relationship methods deliberately omit generic type parameters to test
 * that the plugin resolves property types from the method body AST.
 */
final class Vault extends Model
{
    protected $table = 'vaults';

    // --- Single relations (should resolve to ?RelatedModel) ---

    /** BelongsTo without generics */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** HasOne without generics */
    public function latestPhone(): HasOne
    {
        return $this->hasOne(Phone::class);
    }

    /** MorphOne without generics */
    public function featuredImage(): MorphOne
    {
        return $this->morphOne(Image::class, 'imageable');
    }

    /** MorphTo without generics — related model is polymorphic, not statically determinable */
    public function vaultable(): MorphTo
    {
        return $this->morphTo();
    }

    // --- Collection relations (should resolve to Collection<int, RelatedModel>) ---

    /** HasMany without generics */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /** BelongsToMany without generics */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    /** MorphMany without generics */
    public function comments(): MorphMany
    {
        return $this->morphMany(Comment::class, 'commentable');
    }

    /** HasManyThrough without generics */
    public function mechanics(): HasManyThrough
    {
        return $this->hasManyThrough(Mechanic::class, Car::class);
    }

    /** MorphToMany without generics */
    public function allTags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    /** HasOneThrough without generics */
    public function carOwner(): HasOneThrough
    {
        return $this->hasOneThrough(User::class, Car::class);
    }

    /** morphedByMany — inverse of morphToMany, same Relation class */
    public function morphedPosts(): MorphToMany
    {
        return $this->morphedByMany(Post::class, 'taggable');
    }

    // --- Edge cases ---

    /** No return type at all — plugin must parse the body to find both relation type and model */
    public function images()
    {
        return $this->morphMany(Image::class, 'imageable');
    }

    /** Chained method call: belongsTo(...)->withDefault() */
    public function account(): BelongsTo
    {
        return $this->belongsTo(User::class)->withDefault();
    }
}
