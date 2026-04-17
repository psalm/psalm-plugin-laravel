<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Providers\ModelMetadata;

/**
 * Pre-Laravel-9 accessor style: `public function getFullNameAttribute(): string`.
 *
 * @psalm-immutable
 * @psalm-api
 * @internal
 */
final readonly class LegacyAccessorInfo extends AccessorInfo {}
