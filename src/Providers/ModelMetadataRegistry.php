<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Providers;

use Illuminate\Database\Eloquent\Model;
use Psalm\LaravelPlugin\Providers\ModelMetadata\ModelMetadata;
use Psalm\Progress\Progress;

/**
 * Read-only metadata store for Eloquent models.
 *
 * Populated by {@see ModelMetadata\ModelMetadataRegistryBuilder::warmUp()} during
 * `AfterCodebasePopulated` (parent process, pre-fork) so forked analysis workers
 * inherit a warm cache via copy-on-write.
 *
 * Does NOT own per-model Psalm hook registration — that stays in
 * {@see \Psalm\LaravelPlugin\Handlers\Eloquent\ModelRegistrationHandler}.
 *
 * Does NOT touch `ClassLikeStorage::$custom_metadata` — this registry's cache is
 * process-local and intentionally not persisted by Psalm's incremental analysis
 * cache. Persistent caching is a Phase-4 concern requiring its own serialization
 * strategy.
 *
 * @psalm-external-mutation-free
 * @psalm-api
 * @internal
 */
final class ModelMetadataRegistry
{
    /** @var array<class-string<Model>, ModelMetadata<Model>> */
    private static array $cache = [];

    private static ?Progress $progress = null;

    /**
     * Plugin-init hook. Captures a {@see Progress} handle for deferred warnings
     * during lazy access paths; no model iteration happens here.
     *
     * @psalm-external-mutation-free
     */
    public static function init(Progress $progress): void
    {
        self::$progress = $progress;
    }

    /**
     * Return metadata for a model FQCN.
     *
     * Phase 1: reads from the warm-up cache populated by
     * {@see ModelMetadata\ModelMetadataRegistryBuilder::warmUp()}. Returns null
     * when the class was not warmed up (e.g. not a concrete Model subclass,
     * autoload failed, or storage was missing at warm-up time).
     *
     * Never throws.
     *
     * @param  class-string $modelFqcn
     * @return ModelMetadata<Model>|null
     * @psalm-external-mutation-free
     */
    public static function for(string $modelFqcn): ?ModelMetadata
    {
        return self::$cache[$modelFqcn] ?? null;
    }

    /**
     * Iterate all models that have been warmed up.
     *
     * Completeness guarantee: only meaningful AFTER `AfterCodebasePopulated`
     * has run (i.e. during analysis-time event callbacks). Handlers that run
     * earlier must not assume this set is complete.
     *
     * @return iterable<class-string<Model>, ModelMetadata<Model>>
     * @psalm-external-mutation-free
     * @psalm-api
     */
    public static function all(): iterable
    {
        return self::$cache;
    }

    /**
     * Get the Progress handle captured at init-time, for deferred warnings.
     *
     * @psalm-external-mutation-free
     * @psalm-api
     * @internal
     */
    public static function getProgress(): ?Progress
    {
        return self::$progress;
    }

    /**
     * Store a metadata entry.
     *
     * @internal called only by {@see ModelMetadata\ModelMetadataRegistryBuilder}
     * @psalm-internal Psalm\LaravelPlugin\Providers\ModelMetadata
     * @param class-string<Model> $modelFqcn
     * @param ModelMetadata<Model> $metadata
     * @psalm-external-mutation-free
     */
    public static function store(string $modelFqcn, ModelMetadata $metadata): void
    {
        self::$cache[$modelFqcn] = $metadata;
    }

    /**
     * Clear all cached metadata and captured Progress.
     *
     * @internal called by the builder's reset hook for tests
     * @psalm-internal Psalm\LaravelPlugin\Providers\ModelMetadata
     *
     * @psalm-external-mutation-free
     */
    public static function reset(): void
    {
        self::$cache = [];
        self::$progress = null;
    }
}
