<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Filesystem;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\Internal\MethodIdentifier;
use Psalm\IssueBuffer;
use Psalm\LaravelPlugin\Bootstrap\ApplicationProvider;
use Psalm\LaravelPlugin\Bootstrap\ConfigRepositoryProvider;
use Psalm\LaravelPlugin\Internal\ClosestName;
use Psalm\LaravelPlugin\Issues\UnconfiguredFilesystemDisk;
use Psalm\LaravelPlugin\Stubs\FacadeMapProvider;
use Psalm\Plugin\EventHandler\Event\MethodParamsProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodParamsProviderInterface;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Progress\Progress;
use Psalm\StatementsSource;
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
     * Disk names from `filesystems.disks` that carry a `driver`; null leaves the
     * {@see UnconfiguredFilesystemDisk} diagnostic off. A short list, so no set is built.
     *
     * @var list<string>|null
     */
    private static ?array $disks = null;

    public static function reset(): void
    {
        self::$adapter_return_type = null;
        self::$facade_disk_params = null;
        self::$disks = null;
    }

    /**
     * Arm the {@see UnconfiguredFilesystemDisk} diagnostic with the booted app's disk names.
     *
     * Stays off unless the project's own `bootstrap/app.php` booted cleanly: the Testbench fallback
     * carries only its own `local, public, s3`, which would flag every project-specific disk.
     */
    public static function init(Progress $output): void
    {
        if (!ApplicationProvider::isProjectBootTrusted()) {
            return;
        }

        try {
            $disks = self::namesWithDriver(ConfigRepositoryProvider::get()->get('filesystems.disks'));
        } catch (\Throwable $throwable) {
            $output->warning(
                'Laravel plugin: findUnconfiguredFilesystemDisks is enabled but reading filesystems.disks '
                . "threw: {$throwable->getMessage()}. The UnconfiguredFilesystemDisk check will be skipped.",
            );

            return;
        }

        if ($disks === []) {
            $output->warning(
                'Laravel plugin: findUnconfiguredFilesystemDisks is enabled but filesystems.disks has no '
                . 'entry with a driver. The UnconfiguredFilesystemDisk check will be skipped.',
            );

            return;
        }

        self::$disks = $disks;
    }

    /**
     * A key without a `driver` is a nested group (`disks.tenant.assets`), not a disk:
     * `disk('tenant')` throws at runtime just like an absent key.
     *
     * @return list<string>
     * @psalm-pure
     */
    private static function namesWithDriver(mixed $configured): array
    {
        if (!\is_array($configured)) {
            return [];
        }

        $withDriver = \array_filter(
            $configured,
            static fn(mixed $diskConfig): bool => \is_array($diskConfig) && isset($diskConfig['driver']),
        );

        return \array_map(\strval(...), \array_keys($withDriver));
    }

    /**
     * Register for every surface that exposes `disk()` / `drive()`:
     * - the `Storage` facade (calls go through `__callStatic` → forwarded by Laravel's `@method`),
     * - the concrete `FilesystemManager` (common DI target),
     * - the `Factory` contract (DI by interface — `disk()` is its only method),
     * - the app's root aliases of the facade (`\Storage`): Psalm dispatches by exact FQCN and the
     *   alias is a separate stub class, so it needs its own registration. FacadeMapProvider only
     *   lists aliases the booted app's AliasLoader actually registers.
     *
     * @return list<string>
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return [
            \Illuminate\Support\Facades\Storage::class,
            \Illuminate\Filesystem\FilesystemManager::class,
            \Illuminate\Contracts\Filesystem\Factory::class,
            ...FacadeMapProvider::getFacadeClasses(\Illuminate\Filesystem\FilesystemManager::class),
        ];
    }

    /**
     * Narrow `disk()` / `drive()` to `FilesystemAdapter` unconditionally — the
     * runtime class is the same regardless of the disk name, so we do not read
     * the configured driver and a dynamic `disk($name)` narrows just as a literal
     * `disk('s3')` does.
     *
     * @inheritDoc
     */
    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Type\Union
    {
        if (!isset(self::DISK_METHODS[$event->getMethodNameLowercase()])) {
            return null;
        }

        // Facade-only (where `disk()` is a `@method` pseudo-method): a DI-injected manager may be a
        // userland FilesystemManager subclass resolving disks its own way (an overridden getConfig()),
        // and this provider also fires for subclass receivers. The facade always resolves the app's own manager.
        if (
            self::$disks !== null
            && !self::isRealMethod($event->getSource()->getCodebase(), $event->getFqClasslikeName(), $event->getMethodNameLowercase())
        ) {
            self::checkDiskExists(self::$disks, $event->getCallArgs(), $event->getSource(), $event->getCodeLocation());
        }

        return self::$adapter_return_type ??= new Type\Union([
            new Type\Atomic\TNamedObject(\Illuminate\Filesystem\FilesystemAdapter::class),
        ]);
    }

    /**
     * Flag `disk('s3-old')` / `drive('s3-old')` when a statically known name is not a configured disk.
     * Laravel's `FilesystemManager::resolve()` throws a hard `InvalidArgumentException` for it (no
     * silent fallback to `local`), so the failure mode is availability, not a wrong write target.
     *
     * @param list<string> $disks
     * @param list<Arg> $callArgs
     */
    private static function checkDiskExists(array $disks, array $callArgs, StatementsSource $source, CodeLocation $codeLocation): void
    {
        $diskName = self::knownDiskName($callArgs[0]->value ?? null, $source);

        if ($diskName === null) {
            return;
        }

        // '' and '0' are falsy: `enum_value($name) ?: $this->getDefaultDriver()` sends them to the
        // default disk. Dotted names reach nested config groups (`disks.tenant.assets`) through
        // Laravel's dotted config lookup; `$disks` holds top-level keys only.
        if ($diskName === '' || $diskName === '0' || \str_contains($diskName, '.') || \in_array($diskName, $disks, true)) {
            return;
        }

        $suggestion = ClosestName::find($diskName, $disks);
        $hint = $suggestion === null ? '' : ", did you mean '{$suggestion}'?";

        IssueBuffer::accepts(
            new UnconfiguredFilesystemDisk(
                "Disk '{$diskName}' is not configured in filesystems.disks{$hint}",
                $codeLocation,
            ),
            $source->getSuppressedIssues(),
        );
    }

    /**
     * A literal, a string-backed enum case (`disk()` unwraps it with `enum_value()`), or a class
     * constant typed as one string literal. Reads the AST, not the inferred type: on the facade's
     * `@method` path Psalm runs return-type providers before analysing the arguments.
     */
    private static function knownDiskName(?Expr $name, StatementsSource $source): ?string
    {
        if ($name instanceof String_) {
            return $name->value;
        }

        if (!$name instanceof ClassConstFetch || !$name->class instanceof Name || !$name->name instanceof Identifier) {
            return null;
        }

        // `static::` / `parent::` decline: late static binding and rare in a disk argument.
        /** @psalm-var string|null $resolved */
        $resolved = $name->class->getAttribute('resolvedName');
        $fqcn = $name->class->toLowerString() === 'self'
            ? $source->getFQCLN()
            : ($name->class->isSpecialClassName() ? null : $resolved ?? $name->class->toString());

        if ($fqcn === null) {
            return null;
        }

        $const = $name->name->name;
        $codebase = $source->getCodebase();

        try {
            $storage = $codebase->classlike_storage_provider->get(\strtolower($fqcn));
            // `self::` in a trait binds to the using class, which the trait's storage cannot see.
            if ($storage->is_trait) {
                return null;
            }

            if (isset($storage->enum_cases[$const])) {
                $value = $storage->enum_cases[$const]->getValue($codebase->classlikes);

                // Int-backed and pure cases decline: disk keys are strings.
                return $value instanceof Type\Atomic\TLiteralString ? $value->value : null;
            }

            $type = $codebase->classlikes->getClassConstantType($storage->name, $const, \ReflectionProperty::IS_PRIVATE);
        } catch (\InvalidArgumentException|\UnexpectedValueException|UnpopulatedClasslikeException) {
            return null;
        }

        return $type?->isSingleStringLiteral() === true ? $type->getSingleStringLiteral()->value : null;
    }

    /**
     * Provide explicit params for `disk()` / `drive()` when reached through the
     * `Storage` facade or its root alias. Those only declare these as `@method` (no
     * real method on the Facade class), and registering a return type provider on a
     * class without a matching params provider crashes Psalm 7's
     * `Methods::getMethodParams()` with "Cannot get method params for ..." — the
     * same failure mode documented on {@see \Psalm\LaravelPlugin\Handlers\Auth\AuthHandler::getMethodParams()}.
     *
     * For receivers where `disk()` is a real method (`FilesystemManager`, `Factory`
     * contract) Psalm derives params itself — we return null and let Laravel's
     * signature drift through. Discriminating on real-vs-pseudo rather than a class
     * list keeps this in lockstep with every name getClassLikeNames() registers.
     */
    #[\Override]
    public static function getMethodParams(MethodParamsProviderEvent $event): ?array
    {
        $methodNameLower = $event->getMethodNameLowercase();

        if ($methodNameLower !== 'disk' && $methodNameLower !== 'drive') {
            return null;
        }

        $source = $event->getStatementsSource();

        if (!$source instanceof StatementsSource) {
            return null;
        }

        if (self::isRealMethod($source->getCodebase(), $event->getFqClasslikeName(), $methodNameLower)) {
            return null;
        }

        return self::$facade_disk_params ??= self::buildFacadeDiskParams();
    }

    /**
     * `methodExists()` excludes `@method` pseudo-methods (its `$with_pseudo` flag stays false).
     *
     * @param lowercase-string $methodNameLower
     */
    private static function isRealMethod(Codebase $codebase, string $fqClassName, string $methodNameLower): bool
    {
        return $codebase->methods->methodExists($codebase, new MethodIdentifier($fqClassName, $methodNameLower));
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
