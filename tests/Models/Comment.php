<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

final class Comment extends Model
{
    /**
     * @psalm-return BelongsTo<Post>
     */
    public function post(): BelongsTo
    {
        return $this->belongsTo(Post::class);
    }

    /**
     * Get the owning commentable model.
     */
    public function commentable(): MorphTo
    {
        return $this->morphTo();
    }
}
