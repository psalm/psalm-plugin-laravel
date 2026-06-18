<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Filesystem;

use Psalm\Plugin\EventHandler\Event\MethodParamsProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodParamsProviderInterface;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Storage\FunctionLikeParameter as Param;
use Psalm\Type;

/**
 * Narrows `Storage::disk($name)` / `Storage::drive($name)` (and the same calls on a
 * DI-injected `FilesystemManager` / `Factory` contract) to the concrete
 * {@see \Illuminate\Filesystem\FilesystemAdapter}.
 *
 * Why narrow to the concrete adapter
 * ----------------------------------
 * Laravel declares `disk()` as returning the bare
 * {@see \Illuminate\Contracts\Filesystem\Filesystem} contract, but **every**
 * driver Laravel ships resolves to a `FilesystemAdapter` (or a subclass) at
 * runtime: `local`/`ftp`/`sftp` build a `FilesystemAdapter`, `s3` builds an
 * `AwsS3V3Adapter extends FilesystemAdapter`, and `scoped` delegates (via
 * `build()`) to the wrapped disk's adapter. The contract is a deliberately
 * conservative *declared* type, not the *runtime* type.
 *
 * Two tiers of method are unreachable through the declared `Filesystem` return:
 *   - `url()` is declared on the `Cloud extends Filesystem` sub-contract, not on
 *     the base `Filesystem`. So `Storage::disk('public')->url(...)` — `public`
 *     uses `driver=local`, a file-only disk — was a false positive under the
 *     contract-faithful predecessor, despite being one of the most common
 *     `Storage` calls in Laravel.
 *   - `temporaryUrl()`, `temporaryUploadUrl()`, and `providesTemporaryUrls()` are
 *     declared on **no** contract at all — only on `FilesystemAdapter`. Reaching
 *     `Storage::disk('s3')->temporaryUrl(...)` (issue #802) therefore *requires*
 *     the concrete class; there is no interface we could narrow to instead.
 *
 * (The stream and visibility methods — `readStream()`, `writeStream()`, `put()`,
 * `setVisibility()` — are already on the `Filesystem` contract and were never the
 * problem.) This mirrors Larastan, which also types `disk()` as `FilesystemAdapter`.
 *
 * Tradeoff we accept
 * ------------------
 * A predecessor of this handler (#973/#982) narrowed only `s3` to the `Cloud`
 * contract to keep `disk('local')->url(...)` a (contract-faithful) error. We
 * reverse that: the `Cloud` boundary modelled nothing real (it gated `url()` yet
 * still hid `temporaryUrl()`, an equally standard cloud operation) and it
 * produced a false positive on the single most common URL call in Laravel —
 * `Storage::disk('public')->url(...)`, whose `public` disk uses `driver=local`.
 * Narrowing to the adapter trades that contract signal away: `url()` /
 * `temporaryUrl()` on a disk whose backend has them unconfigured now type-checks
 * (it throws `RuntimeException` at runtime rather than being caught statically).
 * That guarantee was always weak — those methods throw-if-unconfigured even on a
 * disk that "supports" them — so favouring the no-false-positive runtime view is
 * the better bet for real codebases. See #802 and the issue #977 cluster
 * (augmenting the `Filesystem` contract stub for DI-injected call sites a
 * return-narrowing fix cannot touch).
 *
 * A narrower unsoundness we also accept: a custom driver registered via
 * `Storage::extend(...)` / `set(...)` may return a bare `Filesystem` that is
 * *not* a `FilesystemAdapter`. Its factory closure is opaque to static analysis,
 * so we narrow it anyway and `->temporaryUrl()` on such a disk type-checks but
 * fatals at runtime. Stock drivers are the overwhelmingly common case; Larastan
 * makes the same tradeoff.
 *
 * Scope: only `disk()` / `drive()`. `cloud()` (declared `@return Cloud`) and
 * `build()` also resolve to a `FilesystemAdapter` at runtime but are left on
 * their declared types, so `Storage::cloud()->temporaryUrl()` remains a rare
 * residual gap rather than expanding this handler's surface.
 *
 * Note on `put($path, fopen(...))`: under the adapter return type, `put()`'s
 * `$contents` is typed `…|resource` (no `false`), so `put($p, fopen($p, 'r'))`
 * surfaces `PossiblyFalseArgument`. That is a *correct* finding, not a regression
 * — an unchecked `fopen()` can return `false`, which `put()` would silently
 * coerce to an empty-file write. Callers should guard the `fopen()` result.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/802
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/973
 */
final class StorageHandler implements MethodReturnTypeProviderInterface, MethodParamsProviderInterface
{
    /** `drive()` is the long-standing `FilesystemManager` alias for `disk()` — same forwarding, same return contract. */
    private const DISK_METHODS = ['disk' => true, 'drive' => true];

    /**
     * Cached `FilesystemAdapter` return type. The narrowed type has no per-call
     * variation, so a single immutable Union is reused for every successful
     * narrow. Avoids allocating a fresh Union+Atomic pair on every
     * `Storage::disk(...)` call site (an app with hundreds of references would
     * otherwise pay that cost inside the worker fork).
     */
    private static ?Type\Union $adapter_return_type = null;

    /**
     * Cached parameter list for the facade-only params override (see
     * {@see self::getMethodParams()}). Same allocation-avoidance rationale as
     * `$adapter_return_type`.
     *
     * @var list<Param>|null
     */
    private static ?array $facade_disk_params = null;

    /**
     * Register for every surface that exposes `disk()` / `drive()`:
     * - the `Storage` facade (calls go through `__callStatic` → forwarded by Laravel's `@method`),
     * - the concrete `FilesystemManager` (common DI target),
     * - the `Factory` contract (DI by interface — `disk()` is its only method).
     *
     * @return list<string>
     * @psalm-pure
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return [
            \Illuminate\Support\Facades\Storage::class,
            \Illuminate\Filesystem\FilesystemManager::class,
            \Illuminate\Contracts\Filesystem\Factory::class,
        ];
    }

    /**
     * Narrow `disk()` / `drive()` to `FilesystemAdapter` unconditionally — the
     * runtime class is the same regardless of the disk name, so we do not read
     * the configured driver and a dynamic `disk($name)` narrows just as a literal
     * `disk('s3')` does.
     *
     * @inheritDoc
     *
     * @psalm-external-mutation-free
     */
    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Type\Union
    {
        if (!isset(self::DISK_METHODS[$event->getMethodNameLowercase()])) {
            return null;
        }

        return self::$adapter_return_type ??= new Type\Union([
            new Type\Atomic\TNamedObject(\Illuminate\Filesystem\FilesystemAdapter::class),
        ]);
    }

    /**
     * Provide explicit params for `disk()` / `drive()` when reached through the
     * `Storage` facade. The facade only declares these as `@method` (no real
     * method on the Facade class), and registering a return type provider on a
     * class without a matching params provider crashes Psalm 7's
     * `Methods::getMethodParams()` with "Cannot get method params for ..." — the
     * same failure mode documented on {@see \Psalm\LaravelPlugin\Handlers\Auth\AuthHandler::getMethodParams()}.
     *
     * For non-facade receivers (`FilesystemManager`, `Factory` contract) `disk()`
     * is a real method, so Psalm can derive params itself — we return null and
     * let Laravel's signature drift through.
     *
     * @psalm-external-mutation-free
     */
    #[\Override]
    public static function getMethodParams(MethodParamsProviderEvent $event): ?array
    {
        if ($event->getFqClasslikeName() !== \Illuminate\Support\Facades\Storage::class) {
            return null;
        }

        if (!isset(self::DISK_METHODS[$event->getMethodNameLowercase()])) {
            return null;
        }

        return self::$facade_disk_params ??= self::buildFacadeDiskParams();
    }

    /**
     * @return list<Param>
     * @psalm-pure
     */
    private static function buildFacadeDiskParams(): array
    {
        // `disk(\UnitEnum|string|null $name = null)` — mirror the facade @method declaration.
        $name_type = new Type\Union([
            new Type\Atomic\TString(),
            new Type\Atomic\TNamedObject(\UnitEnum::class),
            new Type\Atomic\TNull(),
        ]);

        $name_param = new Param(
            'name',
            false,
            $name_type,
            $name_type,
            is_optional: true,
            default_type: Type::getNull(),
        );

        return [$name_param];
    }
}
