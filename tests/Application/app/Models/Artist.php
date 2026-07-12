<?php

declare(strict_types=1);

namespace App\Models;

use App\Builders\ArtistBuilder;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Model;

/**
 * Opts into a custom builder that sits at the bottom of a two-level builder hierarchy
 * (ArtistBuilder extends FavoriteableBuilder extends Builder), via #[UseEloquentBuilder]
 * (Laravel 12+). Exercises subclass-method resolution through a fluent chain.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1216
 */
#[UseEloquentBuilder(ArtistBuilder::class)]
final class Artist extends Model
{
    protected $table = 'artists';
}
