<?php

declare(strict_types=1);

namespace App\Models;

use App\Builders\ScopedSongBuilder;
use App\Models\Concerns\DeclaresQueryPseudoMethod;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Model;

/**
 * Mimics Koel's Song: a trait declares @method static Builder query(), and the model
 * overrides query() with extra parameters + a custom builder via #[UseEloquentBuilder].
 * The plugin must keep the override's signature authoritative so callers can pass
 * those parameters.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/795
 */
#[UseEloquentBuilder(ScopedSongBuilder::class)]
final class ScopedSong extends Model
{
    use DeclaresQueryPseudoMethod;

    protected $table = 'scoped_songs';

    #[\Override]
    public static function query(?string $type = null, ?Admin $user = null): ScopedSongBuilder
    {
        /** @var ScopedSongBuilder */
        return parent::query();
    }
}
