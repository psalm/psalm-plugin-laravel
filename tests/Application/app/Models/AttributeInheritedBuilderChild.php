<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Concrete model that inherits #[UseEloquentBuilder] from {@see AbstractAttributeBuilderModel}.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/2109
 */
final class AttributeInheritedBuilderChild extends AbstractAttributeBuilderModel {}
