<?php

declare(strict_types=1);

namespace App\Models;

use App\Builders\ArtistBuilder;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Model;

/**
 * Mirrors tests/Application/app/Models/Artist.php — opts into a two-level custom builder
 * (ArtistBuilder extends FavoriteableBuilder extends Builder) via #[UseEloquentBuilder].
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1216
 */
#[UseEloquentBuilder(ArtistBuilder::class)]
final class Artist extends Model
{
    protected $table = 'artists';
}
