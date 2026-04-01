<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Eloquent\Fixtures;

use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Model;

/**
 * Test fixture: model with #[UseEloquentBuilder] pointing to a class that
 * does NOT extend Eloquent\Builder. The plugin should reject this silently.
 */
#[UseEloquentBuilder(\stdClass::class)]
final class InvalidBuilderModel extends Model {}
