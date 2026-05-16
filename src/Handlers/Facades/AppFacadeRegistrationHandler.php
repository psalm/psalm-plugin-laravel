<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Facades;

use Illuminate\Support\Facades\Facade;
use Psalm\Codebase;
use Psalm\LaravelPlugin\Util\AnonymousClassNameDetector;
use Psalm\Plugin\EventHandler\AfterClassLikeVisitInterface;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeVisitEvent;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Plugin\EventHandler\Event\MethodExistenceProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodParamsProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Progress\Progress;
use Psalm\Type\Union;

/**
 * Discovers app-owned Facade subclasses from Psalm's scanned codebase and registers
 * per-class method providers so calls like `App\Facades\Diagnostic::getReport()` resolve
 * when the facade's accessor is container-resolvable at runtime.
 *
 * Why a registration handler (like {@see \Psalm\LaravelPlugin\Handlers\Eloquent\ModelRegistrationHandler}):
 * Psalm's method-provider lookup is keyed on the exact FQCN being called. Registering
 * a single handler for `Facade::class` would not fire for subclasses. We enumerate every
 * concrete `Facade` subclass in the analysed project and bind {@see FacadeMethodHandler}
 * callbacks to each one.
 *
 * First-party `Illuminate\` facades are skipped — Laravel's framework source already
 * ships rich `@method` catalogues on those classes, and {@see \Psalm\LaravelPlugin\Providers\FacadeMapProvider::init()}
 * covers the alias path. `Laravel\` sub-packages (Cashier, Horizon, Telescope, Pulse,
 * Octane, Pennant) ship their own facades whose bindings live in package service providers
 * that may not run in Testbench — autoloading their root class would chain into
 * `BindingResolutionException`. `Mockery\` / `PHPUnit\` are defensive defaults against
 * test doubles that happen to extend `Facade` transitively.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/787
 * @internal
 */
final class AppFacadeRegistrationHandler implements AfterClassLikeVisitInterface, AfterCodebasePopulatedInterface
{
    private const FACADE_FQCN = 'Illuminate\\Support\\Facades\\Facade';

    private const FACADE_FQCN_LOWER = 'illuminate\\support\\facades\\facade';

    /**
     * Set of facade classes whose `getFacadeRoot()` probe has already thrown. Prevents
     * re-running a user service provider factory across the two probe sites
     * (scan phase + populate phase) — Laravel's own `Facade::$resolvedInstance` only
     * caches on success, so without this, a throwing accessor runs twice per facade.
     *
     * @var array<string, true>
     */
    private static array $failedFacades = [];

    /**
     * Scan-phase warnings deferred until {@see self::afterCodebasePopulated()} so they do
     * not splice into Psalm's open `\rN / total...` progress counter (issue #941).
     * `LongProgress::taskDone()` writes the counter without a trailing newline; emitting a
     * warning from `afterClassLikeVisit` would otherwise glue `Warning: ...` onto the same
     * line. Flushed at the top of `afterCodebasePopulated`, between the scan and analysis
     * phases, where the output buffer is between progress writes.
     *
     * @var list<string>
     */
    private static array $pendingScanWarnings = [];

    /**
     * Lazy separator gate: the first warning emission writes a single `PHP_EOL` to terminate
     * an open `\rN / total...` line. Zero-warning runs leave output untouched (no stray blank
     * line). Persists for the lifetime of the process — additional warnings after the first
     * already start at column 0.
     */
    private static bool $wroteScanSeparator = false;

    /**
     * Guards a one-time `register_shutdown_function` registration. Scan workers
     * (`--threads N > 1`) fork the main process; the worker's copy of
     * {@see self::$pendingScanWarnings} never propagates back, so without a shutdown hook a
     * buffered warning would vanish when the worker exits. The hook flushes pending warnings
     * directly to STDERR at worker exit; in the main process the same hook fires after
     * `afterCodebasePopulated` already drained the buffer, so it's a no-op there.
     */
    private static bool $shutdownHookRegistered = false;

    /**
     * Probe `Facade::getFacadeRoot()` at scan time and queue the resolved root class for
     * scanning. We can't do this in {@see self::afterCodebasePopulated()} because by then
     * the scanner has stopped — a root class not already pulled in via other references
     * would be missing its classlike storage when providers look it up.
     *
     * Direct-parent match only: during scan phase, only `$storage->parent_class` is set;
     * the full `parent_classes` chain is built later by the populator. Indirect subclasses
     * (`class X extends CustomBase extends Facade`) fall through to afterCodebasePopulated,
     * where their root class can still be probed but is scanned best-effort (via other
     * references in the project, or not at all).
     */
    #[\Override]
    public static function afterClassLikeVisit(AfterClassLikeVisitEvent $event): void
    {
        $storage = $event->getStorage();

        if ($storage->parent_class === null) {
            return;
        }

        // strcasecmp avoids allocating a lowercased copy per class visited (~10k+ calls
        // on a mid-size project). $storage->parent_class is stored in declared case.
        if (\strcasecmp($storage->parent_class, self::FACADE_FQCN) !== 0) {
            return;
        }

        if (self::isSkippedFacade($storage->name)) {
            return;
        }

        $progress = $event->getCodebase()->progress;
        $rootClass = self::tryGetFacadeRootClass($storage->name, $progress, true);

        if ($rootClass === null) {
            return;
        }

        $event->getCodebase()->scanner->queueClassLikeForScanning($rootClass);
    }

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $codebase = $event->getCodebase();
        $progress = $codebase->progress;

        // Drain warnings that the scan-phase hook deferred. Runs first so the lazy
        // separator terminates Psalm's open `\rN / total...` progress line (issue #941)
        // before any populate-phase warning could emit its own separator. Also re-arms
        // the separator gate so a second analysis in the same process re-emits it.
        self::flushScanWarnings($progress);

        foreach ($codebase->classlike_storage_provider::getAll() as $storage) {
            // Abstract classes include the base Facade itself and user-defined abstract
            // base facades — neither should receive method providers.
            if ($storage->abstract) {
                continue;
            }

            if (!isset($storage->parent_classes[self::FACADE_FQCN_LOWER])) {
                continue;
            }

            if (self::isSkippedFacade($storage->name)) {
                continue;
            }

            // Anonymous classes: Psalm synthesises an FQCN from the file path and position.
            // That name is not autoloadable and the class has no user-authored docblock to
            // parse, so there is nothing for our resolver to do.
            if (
                $storage->stmt_location !== null
                && AnonymousClassNameDetector::isSynthetic($storage->name, $storage->stmt_location->file_path)
            ) {
                continue;
            }

            try {
                if (!\class_exists($storage->name, true)) {
                    // warning (not debug) — debug is a no-op in the default progress, and a user
                    // facade silently losing method resolution is exactly the class of failure
                    // issue #787 was filed to fix. Match ModelRegistrationHandler's convention.
                    // Routed through `emitWarning` so the lazy separator stays consistent with
                    // scan-phase warnings flushed just above (issue #941).
                    self::emitWarning(
                        $progress,
                        "Laravel plugin: skipping facade '{$storage->name}': class could not be loaded by autoloader",
                        false,
                    );
                    continue;
                }
            } catch (\Error|\Exception $error) {
                self::emitWarning(
                    $progress,
                    "Laravel plugin: skipping facade '{$storage->name}': {$error->getMessage()}",
                    false,
                );
                continue;
            }

            // Probe once more here. For direct-parent facades seen in afterClassLikeVisit,
            // Laravel's `Facade::$resolvedInstance` cache makes this call effectively free
            // in the main process. When scanning forks, worker processes inherit the cache
            // via copy-on-write memory but their writes don't propagate back, so the main
            // process may re-probe; `$failedFacades` below prevents user-provider factories
            // from running a second time in that case. `tryGetFacadeRootClass()` emits its
            // own warning on first failure, so we simply `continue` here.
            $rootClass = self::tryGetFacadeRootClass($storage->name, $progress, false);

            if ($rootClass === null) {
                continue;
            }

            self::registerHandlersForFacade($codebase, $storage->name, $rootClass);
        }
    }

    /**
     * Resolve the facade's container-bound root object and return its class.
     *
     * Called by both {@see self::afterClassLikeVisit()} (to queue the result for scanning)
     * and {@see self::afterCodebasePopulated()} (to register method providers). Laravel's
     * `Facade` caches the resolved instance in `static::$resolvedInstance` within a single
     * process, so the second call in the main process is free; in forked-scanner topologies
     * the worker's cache is discarded on exit, and {@see self::$failedFacades} prevents a
     * throwing user-provider factory from re-running in the main process.
     *
     * Works when:
     * - The accessor is a class-string (e.g. `protected static function getFacadeAccessor()
     *   { return MyService::class; }`) — the container auto-wires it via reflection.
     * - The accessor is a string alias bound in our Testbench container (first-party
     *   services like `'cache'`, `'router'`, package bindings registered via discovered
     *   providers).
     *
     * Returns null when the accessor is a string alias bound only by a user service
     * provider that does not run in Testbench — nothing we can do at this layer.
     *
     * `$deferWarnings` defers warning emission until {@see self::flushScanWarnings()} drains
     * the buffer at the start of `afterCodebasePopulated`. `afterClassLikeVisit` passes
     * `true` (issue #941: scan-phase warnings would otherwise glue onto the open `\rN / N...`
     * progress line). `afterCodebasePopulated` passes `false` because the scan phase has
     * already ended by then.
     *
     * @return ?class-string
     */
    public static function tryGetFacadeRootClass(
        string $facadeClass,
        ?Progress $progress = null,
        bool $deferWarnings = false,
    ): ?string {
        // $failedFacades gates both the short-circuit return AND the warning emission,
        // so each failure reason is surfaced to the user exactly once per facade across
        // scan-phase + populate-phase invocations (issue #787: silent losses of method
        // resolution must be visible, not swallowed by the default progress sink).
        if (isset(self::$failedFacades[$facadeClass])) {
            return null;
        }

        // is_subclass_of() invokes the autoloader; guard per FacadeMapProvider::init().
        try {
            if (!\is_subclass_of($facadeClass, Facade::class)) {
                self::$failedFacades[$facadeClass] = true;
                if ($progress instanceof \Psalm\Progress\Progress) {
                    self::emitWarning(
                        $progress,
                        "Laravel plugin: skipping facade '{$facadeClass}': not a subclass of " . Facade::class,
                        $deferWarnings,
                    );
                }

                return null;
            }

            // getFacadeRoot() is untyped (`@return mixed`) and container bindings can
            // resolve to anything; route through the typed helper so the caller does
            // not observe a mixed value directly.
            $rootClass = self::classOfFacadeRoot($facadeClass::getFacadeRoot());

            if ($rootClass === null) {
                self::$failedFacades[$facadeClass] = true;
                if ($progress instanceof \Psalm\Progress\Progress) {
                    self::emitWarning(
                        $progress,
                        "Laravel plugin: skipping facade '{$facadeClass}': getFacadeRoot() returned a non-object value",
                        $deferWarnings,
                    );
                }

                return null;
            }

            return $rootClass;
        } catch (\Throwable $throwable) {
            self::$failedFacades[$facadeClass] = true;
            if ($progress instanceof \Psalm\Progress\Progress) {
                self::emitWarning(
                    $progress,
                    "Laravel plugin: getFacadeRoot() failed for '{$facadeClass}': {$throwable->getMessage()}",
                    $deferWarnings,
                );
            }

            return null;
        }
    }

    /**
     * Emit a plugin warning through Psalm's `Progress` sink, optionally deferring it until
     * `afterCodebasePopulated` flushes the scan-phase buffer (issue #941).
     *
     * When emitting directly, the first call writes a single `PHP_EOL` to terminate Psalm's
     * open `\rN / total...` scan-progress line. Subsequent direct emissions skip the
     * separator — they already start at column 0.
     *
     * `@internal` so the surface area stays inside this handler. `tryGetFacadeRootClass`
     * and the populate-phase autoload checks are the only call sites.
     *
     * @internal
     */
    private static function emitWarning(Progress $progress, string $message, bool $defer): void
    {
        if ($defer) {
            self::registerShutdownFlushOnce();
            self::$pendingScanWarnings[] = $message;
            return;
        }

        if (!self::$wroteScanSeparator) {
            self::$wroteScanSeparator = true;
            $progress->write(\PHP_EOL);
        }

        $progress->warning($message);
    }

    /**
     * Drain {@see self::$pendingScanWarnings} into the given progress sink. Called from
     * {@see self::afterCodebasePopulated()} between the scan and analysis phases — that
     * window is the only place where the output stream is guaranteed to be between
     * `\rN / total...` writes, so the lazy separator can cleanly terminate the open line.
     *
     * Each drained message routes through {@see self::emitWarning()} with `$defer = false`
     * so the separator is written once for the whole batch.
     *
     * Also re-arms {@see self::$wroteScanSeparator} so a second analysis run in the same
     * process (CLI `checkPaths()` re-entry, daemon / LSP re-analyze loops) writes a fresh
     * separator. Without this, the gate would stay `true` from the prior run and the new
     * run's open `\rN / total...` line would not be terminated — reintroducing #941.
     *
     * @internal
     */
    private static function flushScanWarnings(Progress $progress): void
    {
        // Re-arm the gate first so the first emission below writes the terminating
        // `PHP_EOL`. Skipping the early-return path: even when the buffer is empty, a
        // prior run may have left the gate `true`; resetting unconditionally guarantees
        // the next direct emission in this run (e.g. populate-phase autoload warnings)
        // can still terminate the scan-counter line if one is open.
        self::$wroteScanSeparator = false;

        if (self::$pendingScanWarnings === []) {
            return;
        }

        $messages = self::$pendingScanWarnings;
        self::$pendingScanWarnings = [];

        foreach ($messages as $message) {
            self::emitWarning($progress, $message, false);
        }
    }

    /**
     * Last-resort drain for scan worker processes (`--threads N > 1`). Workers fork from
     * the main process and inherit a copy of {@see self::$pendingScanWarnings} via
     * copy-on-write; any push the worker makes is discarded on `exit`. Registering a
     * shutdown function ensures the worker prints its buffered warnings to STDERR before
     * dying. In the main process this fires only after `afterCodebasePopulated` already
     * flushed the buffer, so the loop is a no-op.
     *
     * Direct `fwrite($stream, ...)` instead of `Progress::warning()` because we don't hold
     * a `Progress` reference at PHP shutdown — and worker progress output is already wedged
     * with its own per-task counter line at this point, so a wedged warning is the best we
     * can do without leaking the data entirely.
     *
     * `$stream` defaults to `STDERR` for production use; the parameter is a test seam so
     * unit tests can verify the format and drain semantics against a buffer they own.
     * `register_shutdown_function` invokes this with no arguments, so the default is what
     * fires in real runs.
     *
     * @param resource|null $stream
     * @internal
     */
    public static function flushPendingOnShutdown($stream = null): void
    {
        // Symmetry with `flushScanWarnings`: any drain site that empties the buffer also
        // re-arms the separator gate so subsequent emissions know to terminate an open
        // scan-counter line. The worker process exits right after this drain so the gate's
        // state never matters in practice; the reset hardens against future callers that
        // might invoke this drain mid-process.
        self::$wroteScanSeparator = false;

        if (self::$pendingScanWarnings === []) {
            return;
        }

        // Resolve at call time, not as a default parameter value — PHP disallows resource
        // constants like `STDERR` in parameter defaults.
        $stream ??= \STDERR;

        $messages = self::$pendingScanWarnings;
        self::$pendingScanWarnings = [];

        foreach ($messages as $message) {
            \fwrite($stream, \PHP_EOL . 'Warning: ' . $message . \PHP_EOL);
        }
    }

    private static function registerShutdownFlushOnce(): void
    {
        if (self::$shutdownHookRegistered) {
            return;
        }

        self::$shutdownHookRegistered = true;
        \register_shutdown_function([self::class, 'flushPendingOnShutdown']);
    }

    /**
     * Narrow the untyped `Facade::getFacadeRoot()` result to a class-string of its
     * runtime object, or null for any non-object value. The `mixed` parameter is a
     * deliberate boundary: it contains the untyped value inside this helper so the
     * caller's local types stay precise (keeps project-wide Psalm type coverage at 100%).
     *
     * @return ?class-string
     * @psalm-pure
     */
    private static function classOfFacadeRoot(mixed $root): ?string
    {
        return \is_object($root) ? \get_class($root) : null;
    }

    /** @param class-string $rootClass */
    private static function registerHandlersForFacade(
        Codebase $codebase,
        string $facadeClass,
        string $rootClass,
    ): void {
        $methods = $codebase->methods;

        $methods->existence_provider->registerClosure(
            $facadeClass,
            static fn(MethodExistenceProviderEvent $event): ?bool
                => FacadeMethodHandler::doesMethodExist($event, $rootClass),
        );
        $methods->params_provider->registerClosure(
            $facadeClass,
            static fn(MethodParamsProviderEvent $event): ?array
                => FacadeMethodHandler::getMethodParams($event, $rootClass),
        );
        $methods->return_type_provider->registerClosure(
            $facadeClass,
            static fn(MethodReturnTypeProviderEvent $event): ?Union
                => FacadeMethodHandler::getReturnType($event, $rootClass),
        );
    }

    /**
     * Facades we never register against. First-party `Illuminate\` facades already ship with
     * `@method` catalogues (and are covered by {@see \Psalm\LaravelPlugin\Providers\FacadeMapProvider::init()}
     * for the alias path). `Laravel\` sub-packages (Cashier, Horizon, Telescope, Pulse,
     * Octane, Pennant) register their accessors via package service providers that may not
     * run in our Testbench boot — probing them would autoload classes that immediately throw
     * `BindingResolutionException`. `Mockery\` / `PHPUnit\` guard against test doubles that
     * happen to extend `Facade` transitively.
     *
     * @psalm-pure
     */
    private static function isSkippedFacade(string $fqcn): bool
    {
        return \str_starts_with($fqcn, 'Illuminate\\')
            || \str_starts_with($fqcn, 'Laravel\\')
            || \str_starts_with($fqcn, 'Mockery\\')
            || \str_starts_with($fqcn, 'PHPUnit\\');
    }

}
