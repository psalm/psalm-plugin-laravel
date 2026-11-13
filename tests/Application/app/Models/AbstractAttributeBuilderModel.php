<?php

declare(strict_types=1);

namespace App\Models;

use App\Builders\AttributeInheritedBuilder;
use Illuminate\Database\Eloquent\Attributes\UseEloquentBuilder;
use Illuminate\Database\Eloquent\Model;

/**
 * Abstract base carrying #[UseEloquentBuilder]; Laravel resolves the attribute through the class
 * hierarchy, so {@see AttributeInheritedBuilderChild} gets the custom builder.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/2109
 */
#[UseEloquentBuilder(AttributeInheritedBuilder::class)]
abstract class AbstractAttributeBuilderModel extends Model {}
