<?php

declare(strict_types=1);

namespace App\Builders;

use App\Models\ScopedSong;
use Illuminate\Database\Eloquent\Builder;

/**
 * Custom builder without @template parameters, mirroring Koel's SongBuilder shape.
 *
 * @extends Builder<ScopedSong>
 */
final class ScopedSongBuilder extends Builder {}
