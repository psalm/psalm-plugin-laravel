--FILE--
<?php declare(strict_types=1);

use App\Models\Car;
use App\Models\Image;
use App\Models\Mechanic;
use App\Models\Phone;
use App\Models\Post;
use App\Models\Tag;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Database\Eloquent\Relations\MorphToMany;

/**
 * Regression test for #597: Psalm 7 beta17 does not resolve `$this` as a template
 * parameter inside trait methods, causing MixedReturnStatement on relationship calls.
 * The stub uses `static` instead to work around this.
 *
 * Each method below must NOT produce MixedReturnStatement.
 */

class RelationStaticModel extends Model
{
    /** @return HasOne<Phone, static> */
    public function phone(): HasOne
    {
        return $this->hasOne(Phone::class);
    }

    /** @return HasMany<Post, static> */
    public function posts(): HasMany
    {
        return $this->hasMany(Post::class);
    }

    /** @return BelongsTo<User, static> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsToMany<Tag, static> */
    public function tags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class);
    }

    /** @return HasManyThrough<Mechanic, Car, static> */
    public function mechanics(): HasManyThrough
    {
        return $this->hasManyThrough(Mechanic::class, Car::class);
    }

    /** @return MorphMany<Image, static> */
    public function images(): MorphMany
    {
        return $this->morphMany(Image::class, 'imageable');
    }

    /** @return MorphTo<Model, static> */
    public function taggable(): MorphTo
    {
        return $this->morphTo();
    }

    /** @return MorphToMany<Tag, static> */
    public function allTags(): MorphToMany
    {
        return $this->morphToMany(Tag::class, 'taggable');
    }

    /** @return MorphToMany<Post, static> */
    public function morphedPosts(): MorphToMany
    {
        return $this->morphedByMany(Post::class, 'taggable');
    }
}
?>
--EXPECT--
