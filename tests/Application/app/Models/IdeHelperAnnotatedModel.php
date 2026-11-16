<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Carries the `@mixin \Eloquent` that laravel-ide-helper `models --write` appends to every model. Before Psalm 7.5
 * a class's own `@mixin` replaced the inherited `@mixin Builder<static>` (vimeo/psalm#13671), so builder calls
 * failed here. The custom builder (via `newEloquentBuilder()`) and SoftDeletes come from {@see InheritedBuilderModel}.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/2297
 *
 * @mixin \Eloquent
 */
final class IdeHelperAnnotatedModel extends InheritedBuilderModel {}
