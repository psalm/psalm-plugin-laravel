<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Providers\ModelMetadata;

/**
 * Boolean feature flags derived from the traits a model uses.
 *
 * Centralized so consumers don't each walk `ClassLikeStorage::$used_traits`
 * independently. {@see $usesTimestamps} reflects the `$timestamps` property
 * (false disables `created_at` / `updated_at` handling).
 *
 * @psalm-immutable
 * @psalm-api
 * @internal
 */
final readonly class TraitFlags
{
    public function __construct(
        public bool $hasSoftDeletes,
        public bool $hasUuids,
        public bool $hasUlids,
        public bool $hasFactory,
        public bool $hasApiTokens,
        public bool $hasNotifications,
        public bool $hasGlobalScopes,
        public bool $usesTimestamps,
    ) {}
}
