<?php

declare(strict_types=1);

namespace PsalterFixture\Models;

use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Model;

/**
 * Registers the builder through the attribute, not an override, so only the builder check guards it: its
 * `make()` does extra work that `new` would skip.
 */
#[UseEloquentBuilder(ModelMakeOverridingBuilder::class)]
class ModelMakeAttributeBuilderModel extends Model {}
