<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Providers\ModelMetadata;

/**
 * Pre-Laravel-9 mutator style: `public function setFullNameAttribute($value): void`.
 *
 * @psalm-immutable
 * @psalm-api
 * @internal
 */
final readonly class LegacyMutatorInfo extends MutatorInfo {}
