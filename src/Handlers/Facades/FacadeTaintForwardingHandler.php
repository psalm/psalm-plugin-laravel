<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Facades;

use Psalm\Codebase;
use Psalm\Internal\MethodIdentifier;
use Psalm\Plugin\EventHandler\AfterCodebasePopulatedInterface;
use Psalm\Plugin\EventHandler\Event\AfterCodebasePopulatedEvent;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\MethodStorage;

/**
 * Restores taint sinks on facade *static* calls (`Storage::get($path)`,
 * `Artisan::call($cmd)`, ...), which are otherwise silent.
 *
 * Mechanism
 * ---------
 * A facade declares its forwarded surface as class-level `@method` tags, resolved
 * through `__callStatic`. Psalm models those as *pseudo* methods and
 * `AtomicStaticCallAnalyzer::checkPseudoMethod()` hands their `MethodStorage->params`
 * to `ArgumentsAnalyzer`. A sink is a per-parameter bitmask
 * ({@see \Psalm\Storage\FunctionLikeParameter::$sinks}) populated only from a real
 * `@psalm-taint-sink` docblock, and a `@method` tag has no per-parameter docblock to
 * carry one — so pseudo params always arrive with `sinks === 0` and the sink-apply
 * branch in `ArgumentsAnalyzer` never fires. The annotated methods live on the class
 * the facade forwards to, which that code path never consults.
 *
 * So: after population, copy each target method's parameter sinks onto the facade's
 * same-named pseudo parameter. This adds detection only — it never removes a sink,
 * and it leaves types, return types and resolution untouched.
 *
 * Why a hardcoded map
 * -------------------
 * Deliberately not derived from {@see \Psalm\LaravelPlugin\Stubs\FacadeMapProvider}.
 * That map answers "what does this alias resolve to at runtime", which is the facade
 * *root* — for `Http` and `Process` the root (`Client\Factory` / `Process\Factory`) is
 * a builder whose own `__call` forwards to the object that actually carries the sinks
 * (`PendingRequest` / `PendingProcess`). The forwarding target is a security fact about
 * each facade, so it is stated explicitly and verified against the vendor signatures.
 *
 * To extend: add `Facade::class => [TargetClass::class]` to {@see self::FACADE_TARGETS},
 * confirm the facade's `@method` tags mirror the target's real signatures, and add a
 * `.phpt` under `tests/Type/tests/TaintAnalysis/`.
 *
 * Ordering and lifecycle
 * ----------------------
 * Order among `AfterCodebasePopulated` handlers is irrelevant here: the only inputs are
 * pseudo-method and declaring-method storage, both finalised by the populator before the
 * event fires, and no other handler writes `$sinks`. The handler holds no static state,
 * so it needs no `reset()` in {@see \Psalm\LaravelPlugin\Plugin}: the map is a constant
 * and `|=` is idempotent, so a repeated invocation over the same Codebase is a no-op.
 */
final class FacadeTaintForwardingHandler implements AfterCodebasePopulatedInterface
{
    /**
     * Facade => classes whose real methods carry the sinks the facade forwards to.
     *
     * `Http` / `Process` point at the pending-request / pending-process builder rather
     * than the facade root, because the root's `__call` is what forwards there (see the
     * class docblock). Every other entry is the facade's own root class.
     *
     * @var array<class-string, non-empty-list<class-string>>
     */
    private const FACADE_TARGETS = [
        \Illuminate\Support\Facades\Storage::class => [\Illuminate\Filesystem\FilesystemAdapter::class],
        \Illuminate\Support\Facades\File::class => [\Illuminate\Filesystem\Filesystem::class],
        \Illuminate\Support\Facades\Artisan::class => [\Illuminate\Foundation\Console\Kernel::class],
        \Illuminate\Support\Facades\Redirect::class => [\Illuminate\Routing\Redirector::class],
        \Illuminate\Support\Facades\Response::class => [\Illuminate\Routing\ResponseFactory::class],
        \Illuminate\Support\Facades\Http::class => [\Illuminate\Http\Client\PendingRequest::class],
        \Illuminate\Support\Facades\Process::class => [\Illuminate\Process\PendingProcess::class],
        \Illuminate\Support\Facades\View::class => [\Illuminate\View\Factory::class],
    ];

    #[\Override]
    public static function afterCodebasePopulated(AfterCodebasePopulatedEvent $event): void
    {
        $codebase = $event->getCodebase();

        foreach (self::FACADE_TARGETS as $facadeFqcn => $targetFqcns) {
            $facade = self::getClassLikeStorage($codebase, $facadeFqcn);

            if (!$facade instanceof ClassLikeStorage) {
                continue;
            }

            foreach ($targetFqcns as $targetFqcn) {
                $target = self::getClassLikeStorage($codebase, $targetFqcn);

                if (!$target instanceof ClassLikeStorage) {
                    continue;
                }

                self::forwardSinks($codebase, $facade, $target);
            }
        }
    }

    private static function forwardSinks(Codebase $codebase, ClassLikeStorage $facade, ClassLikeStorage $target): void
    {
        foreach ($facade->pseudo_static_methods as $methodNameLower => $pseudoMethod) {
            $declaringId = $target->declaring_method_ids[$methodNameLower] ?? null;

            // A facade tag the target does not declare — e.g. `Storage::disk()`, which
            // lives on the manager, not on the adapter we map to. Nothing to forward.
            if (!$declaringId instanceof MethodIdentifier) {
                continue;
            }

            $realMethod = self::getMethodStorage($codebase, $declaringId);

            if (!$realMethod instanceof MethodStorage) {
                continue;
            }

            foreach ($realMethod->params as $offset => $realParam) {
                if ($realParam->sinks === 0) {
                    continue;
                }

                $pseudoParam = $pseudoMethod->params[$offset] ?? null;

                // Match on offset AND name. Laravel generates facade `@method` tags from
                // the target signature, so both agree today; requiring the name means a
                // future drift degrades to the pre-existing miss rather than moving the
                // sink onto an unrelated argument and inventing a false positive.
                if ($pseudoParam === null || $pseudoParam->name !== $realParam->name) {
                    continue;
                }

                $pseudoParam->sinks |= $realParam->sinks;
            }
        }
    }

    /**
     * @param class-string $fqcn
     *
     * @psalm-mutation-free
     */
    private static function getClassLikeStorage(Codebase $codebase, string $fqcn): ?ClassLikeStorage
    {
        try {
            return $codebase->classlike_storage_provider->get($fqcn);
        } catch (\InvalidArgumentException) {
            // Facade or target outside the scanned set (trimmed vendor, partial scan).
            // Silent: the facade keeps its current, sink-free behaviour.
            return null;
        }
    }

    /**
     * Same `getStorage()` throw contract as {@see \Psalm\LaravelPlugin\Handlers\Application\ContractMethodBridgeHandler::getMethodStorage()}
     * — `InvalidArgumentException` for an unknown class, `UnexpectedValueException` for a
     * method the storage index disagrees about. Keep the catch set in sync.
     *
     * @psalm-mutation-free
     */
    private static function getMethodStorage(Codebase $codebase, MethodIdentifier $methodId): ?MethodStorage
    {
        try {
            return $codebase->methods->getStorage($methodId);
        } catch (\InvalidArgumentException|\UnexpectedValueException) {
            return null;
        }
    }
}
