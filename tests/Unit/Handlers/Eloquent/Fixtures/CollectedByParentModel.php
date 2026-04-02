<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Fixtures;

use App\Collections\PostCollection;
use Illuminate\Database\Eloquent\Attributes\CollectedBy;
use Illuminate\Database\Eloquent\Model;

/**
 * Test fixture: non-final model with #[CollectedBy] to test attribute inheritance.
 */
#[CollectedBy(PostCollection::class)]
class CollectedByParentModel extends Model {}
