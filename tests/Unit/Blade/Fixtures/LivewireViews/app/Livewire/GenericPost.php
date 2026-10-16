<?php

declare(strict_types=1);

namespace App\Livewire;

use App\Models\Post;

/**
 * @extends GenericBase<Post>
 */
final class GenericPost extends GenericBase
{
    public int $page = 1;
}
