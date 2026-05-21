<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Filesystem;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ConstFetch;
use PhpParser\Node\Scalar\String_;
use Psalm\Plugin\EventHandler\Event\MethodParamsProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodParamsProviderInterface;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Storage\FunctionLikeParameter as Param;
use Psalm\Type;

/**
 * Narrows `Storage::disk($name)` / `Storage::drive($name)` (and the same calls on a
 * DI-injected `FilesystemManager` / `Factory` contract) to
 * {@see \Illuminate\Contracts\Filesystem\Cloud} when the disk's configured driver
 * is a built-in Laravel cloud driver. Otherwise returns null to fall through to
 * the declared `Filesystem` return type from Laravel's `@method` catalogue on
 * the {@see \Illuminate\Support\Facades\Storage} facade.
 *
 * Why a handler instead of a stub
 * --------------------------------
 * Larastan's fix vector (and the one suggested in issue #973) is to stub
 * `Storage::disk()` to return the concrete `\Illuminate\Filesystem\FilesystemAdapter`
 * for every call. That conflates the Cloud and Filesystem contracts: it makes
 * `Storage::disk('local')->url('foo.png')` type-check even though the `local`
 * driver does not provide `url()` on the Filesystem contract (Laravel only
 * declares `url()` on `Cloud extends Filesystem`). At runtime `LocalFilesystemAdapter`
 * happens to support `url()` because every shipped adapter extends
 * `FilesystemAdapter`, but the contract distinction is intentional — projects
 * that swap a `local` disk for a custom file-only adapter would silently break.
 *
 * The correct layer is here: read `filesystems.disks.<name>.driver` from the
 * user's config and narrow to `Cloud` only for the cloud drivers Laravel ships
 * (currently just `'s3'`, the only built-in `createXDriver()` typed `@return Cloud`).
 * Disks backed by `local`/`ftp`/`sftp`/`scoped` keep the contract-correct
 * `Filesystem` return type, and `disk('local')->url(...)` remains a (correctly
 * reported) `UndefinedInterfaceMethod`.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/973
 */
final class StorageHandler implements MethodReturnTypeProviderInterface, MethodParamsProviderInterface
{
    /** `drive()` is the long-standing `FilesystemManager` alias for `disk()` — same forwarding, same return contract. */
    private const DISK_METHODS = ['disk' => true, 'drive' => true];

    /**
     * Built-in Laravel driver names whose `createXDriver()` returns
     * {@see \Illuminate\Contracts\Filesystem\Cloud}. Per
     * `Illuminate\Filesystem\FilesystemManager`, only `createS3Driver` declares a
     * Cloud return type; `local`, `ftp`, `sftp`, and `scoped` all return the base
     * `Filesystem` contract. Custom drivers registered via `Storage::extend(...)`
     * are opaque to static analysis and never narrowed.
     */
    private const CLOUD_DRIVERS = ['s3' => true];

    /**
     * Cached `Cloud` return type. The narrowed type has no per-call variation, so
     * a single immutable Union is reused for every successful narrow. Avoids
     * allocating a fresh Union+Atomic pair on every `Storage::disk('s3')` call
     * site (an app with hundreds of S3 references would otherwise pay that cost
     * inside the worker fork).
     */
    private static ?Type\Union $cloud_return_type = null;

    /**
     * Cached parameter list for the facade-only params override (see
     * {@see self::getMethodParams()}). Same allocation-avoidance rationale as
     * `$cloud_return_type`.
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

    /** @inheritDoc */
    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Type\Union
    {
        if (!isset(self::DISK_METHODS[$event->getMethodNameLowercase()])) {
            return null;
        }

        $analyzer = FilesystemConfigAnalyzer::instance();

        $disk_name = self::resolveDiskName($event->getCallArgs(), $analyzer);
        if ($disk_name === null) {
            return null;
        }

        $driver = $analyzer->getDriverForDisk($disk_name);
        if ($driver === null || !isset(self::CLOUD_DRIVERS[$driver])) {
            return null;
        }

        return self::$cloud_return_type ??= new Type\Union([
            new Type\Atomic\TNamedObject(\Illuminate\Contracts\Filesystem\Cloud::class),
        ]);
    }

    /**
     * Extract the disk name argument as a literal string. Returns null when the
     * call has a dynamic argument we cannot resolve at analysis time.
     *
     * `disk()` with no argument or `disk(null)` both fall back to the configured
     * default disk (`filesystems.default`), mirroring `FilesystemManager::disk()`'s
     * `$name = enum_value($name) ?: $this->getDefaultDriver()`.
     *
     * `UnitEnum` literal cases (`Storage::disk(MyDisk::S3)`) are intentionally not
     * resolved today — Psalm would need a `ClassConstFetch` walk against the
     * scanner's enum case storage. Falls through to `null` (no narrowing), which
     * stays sound at the cost of a missed opportunity.
     *
     * @param list<Arg> $args
     */
    private static function resolveDiskName(array $args, FilesystemConfigAnalyzer $analyzer): ?string
    {
        if ($args === []) {
            return $analyzer->getDefaultDisk();
        }

        $first = $args[0]->value;

        if ($first instanceof String_) {
            return $first->value;
        }

        if ($first instanceof ConstFetch && $first->name->toLowerString() === 'null') {
            return $analyzer->getDefaultDisk();
        }

        return null;
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
