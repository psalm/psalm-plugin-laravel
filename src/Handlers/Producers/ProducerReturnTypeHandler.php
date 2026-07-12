<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Producers;

use Psalm\Codebase;
use Psalm\Internal\MethodIdentifier;
use Psalm\LaravelPlugin\Stubs\FacadeMapProvider;
use Psalm\Plugin\EventHandler\Event\MethodParamsProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodParamsProviderInterface;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\StatementsSource;
use Psalm\Storage\FunctionLikeParameter;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Narrows stable Laravel "producer" methods — methods whose framework
 * implementation hard-constructs one specific concrete with no extension
 * point — from their declared contract return to that concrete.
 *
 * Provenance-based, not type-widening: a bare contract-typed value (parameter,
 * property, mock, custom implementation) exposes only the contract. Providers
 * are registered on the producer class, its canonical facade, and that
 * facade's root aliases only — never on a contract FQCN.
 *
 * Registered families (verified against Laravel 12.x/13.x source):
 *  - `PasswordBrokerManager::broker()` / `Password::broker()`:
 *    `PasswordBrokerManager::resolve()` hard-constructs
 *    `new \Illuminate\Auth\Passwords\PasswordBroker(...)` — no custom
 *    broker-driver extension API.
 *  - `Factory::make()/file()/first()` / `View::make()/file()/first()`:
 *    `Factory::viewInstance()` hard-constructs `new \Illuminate\View\View(...)`.
 *    viewInstance() is protected, so a Factory subclass overriding it while
 *    inheriting make() still narrows to the stock View here — the same accepted
 *    bounded trade-off as CacheManager's nonconforming custom creator. If an app
 *    substitutes a different implementation this can produce a false negative (a
 *    stock-only method resolves though the substitute lacks it) and, for a producer
 *    whose stock concrete is not Macroable, a false positive (a substitute-only
 *    method flagged undefined; the stock View's __call masks this for the View
 *    family, but PasswordBroker would exhibit it). We accept it because every
 *    standard app returns the stock concrete and a substitute is rare; a caller
 *    that hits it can suppress at the call site.
 *
 * A handler (not a stub) is required for the facade paths: a facade's
 * `@method static` pseudo-tags shadow any real method a redeclaring stub
 * would add, so static calls must be resolved here. Instance calls on the
 * producer route through the same handler for one source of truth and one
 * shared drift guard.
 *
 * @internal
 */
final class ProducerReturnTypeHandler implements MethodReturnTypeProviderInterface, MethodParamsProviderInterface
{
    /**
     * @var list<array{
     *     producer: class-string,
     *     facade: class-string,
     *     methods: list<lowercase-string>,
     *     contract: class-string,
     *     concrete: class-string,
     * }>
     */
    private const FAMILIES = [
        [
            'producer' => \Illuminate\Auth\Passwords\PasswordBrokerManager::class,
            'facade' => \Illuminate\Support\Facades\Password::class,
            'methods' => ['broker'],
            'contract' => \Illuminate\Contracts\Auth\PasswordBroker::class,
            'concrete' => \Illuminate\Auth\Passwords\PasswordBroker::class,
        ],
        [
            'producer' => \Illuminate\View\Factory::class,
            'facade' => \Illuminate\Support\Facades\View::class,
            'methods' => ['make', 'file', 'first'],
            'contract' => \Illuminate\Contracts\View\View::class,
            'concrete' => \Illuminate\View\View::class,
        ],
    ];

    /**
     * Lazily-built reverse index: lowercased producer/facade/alias FQCN → its family.
     * Built once per Psalm run — FacadeMapProvider's map does not change mid-analysis.
     *
     * @var array<lowercase-string, array{
     *     producer: class-string,
     *     facade: class-string,
     *     methods: list<lowercase-string>,
     *     contract: class-string,
     *     concrete: class-string,
     * }>|null
     */
    private static ?array $classToFamily = null;

    /**
     * Drop the reverse index so it rebuilds from FacadeMapProvider on the next
     * lookup. Called once per plugin invocation because a reused process (Psalm's
     * language server, or back-to-back analyses) can boot a different app whose
     * alias registry differs. Must run before the handler's providers are
     * registered so getClassLikeNames() and resolveFamily() agree.
     *
     * @psalm-external-mutation-free
     */
    public static function reset(): void
    {
        self::$classToFamily = null;
    }

    /**
     * @inheritDoc
     * @return list<class-string>
     * @psalm-external-mutation-free
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        $names = [];

        foreach (self::FAMILIES as $family) {
            $names = [...$names, ...self::familyClassNames($family)];
        }

        return \array_values(\array_unique($names));
    }

    /** @inheritDoc */
    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        $family = self::resolveFamily($event->getFqClasslikeName());

        if ($family === null) {
            return null;
        }

        $methodNameLower = $event->getMethodNameLowercase();

        if (!\in_array($methodNameLower, $family['methods'], true)) {
            return null;
        }

        $codebase = $event->getSource()->getCodebase();

        if (self::isRealMethod($codebase, $event->getFqClasslikeName(), $methodNameLower)) {
            $declared = self::realReturnType($codebase, $event->getFqClasslikeName(), $methodNameLower);
        } else {
            // Pseudo path (facade/alias static call). The params-provider invariant
            // below is mandatory — see getMethodParams().
            if (self::pseudoMethodParams($codebase, $family['facade'], $methodNameLower) === null) {
                return null;
            }

            $declared = self::pseudoReturnType($codebase, $family['facade'], $methodNameLower);
        }

        // Drift guard + narrowing in one step: replace only the contract atomic
        // with the concrete, preserving any siblings. Returns null (no narrowing)
        // if the declared return no longer names the contract we verified against
        // source. Replacing the whole union would silently drop a future
        // `Contract|null` down to a non-null concrete.
        return self::narrowContract($declared, $family['contract'], $family['concrete']);
    }

    /**
     * Mandatory companion to the return provider: a non-null return type on a
     * pseudo-method whose params can't be supplied fatals Psalm with "Cannot get
     * method params". Both providers gate the pseudo path on the same
     * `pseudo_static_methods` lookup, so return-provider-non-null-on-pseudo
     * always implies params-provider-non-null.
     *
     * @inheritDoc
     */
    #[\Override]
    public static function getMethodParams(MethodParamsProviderEvent $event): ?array
    {
        $family = self::resolveFamily($event->getFqClasslikeName());

        if ($family === null) {
            return null;
        }

        $methodNameLower = $event->getMethodNameLowercase();

        if (!\in_array($methodNameLower, $family['methods'], true)) {
            return null;
        }

        $source = $event->getStatementsSource();

        if (!$source instanceof StatementsSource) {
            return null;
        }

        $codebase = $source->getCodebase();

        if (self::isRealMethod($codebase, $event->getFqClasslikeName(), $methodNameLower)) {
            // Real method: return null so Psalm reflects the vendor signature —
            // keeps 12.x/13.x param drift (e.g. `\UnitEnum|string|null $name`)
            // correct for free instead of us hardcoding it.
            return null;
        }

        return self::pseudoMethodParams($codebase, $family['facade'], $methodNameLower);
    }

    /**
     * @return array{
     *     producer: class-string,
     *     facade: class-string,
     *     methods: list<lowercase-string>,
     *     contract: class-string,
     *     concrete: class-string,
     * }|null
     * @psalm-external-mutation-free
     */
    private static function resolveFamily(string $fqClasslikeName): ?array
    {
        if (self::$classToFamily === null) {
            $classToFamily = [];

            foreach (self::FAMILIES as $family) {
                foreach (self::familyClassNames($family) as $name) {
                    $classToFamily[\strtolower($name)] = $family;
                }
            }

            self::$classToFamily = $classToFamily;
        }

        return self::$classToFamily[\strtolower($fqClasslikeName)] ?? null;
    }

    /**
     * Single source for which FQCNs a family answers for: the producer class, its
     * canonical facade, and the app's configured root aliases. getClassLikeNames()
     * (registration) and resolveFamily() (dispatch) must never drift apart.
     *
     * @param array{producer: class-string, facade: class-string, ...} $family
     * @return list<class-string>
     * @psalm-external-mutation-free
     */
    private static function familyClassNames(array $family): array
    {
        return [
            $family['producer'],
            $family['facade'],
            ...FacadeMapProvider::getFacadeClasses($family['producer']),
        ];
    }

    /**
     * True when the method is really declared on the receiver (or inherited from
     * the producer via subclassing — Psalm falls back to the declaring method id,
     * so the event still reports the producer's own FQCN). False means a facade
     * `@method` pseudo-tag.
     *
     * Psalm 6's `Internal\Codebase\Methods::methodExists()` takes the `MethodIdentifier`
     * as its first argument (no leading `$codebase`, unlike Psalm 7); the public
     * `Codebase::methodExists()` wrapper always passes `with_pseudo: true`, so this bypass
     * is required either way.
     *
     * @param lowercase-string $methodNameLower
     */
    private static function isRealMethod(Codebase $codebase, string $fqClasslikeName, string $methodNameLower): bool
    {
        return $codebase->methods->methodExists(new MethodIdentifier($fqClasslikeName, $methodNameLower));
    }

    /**
     * @param lowercase-string $methodNameLower
     * @psalm-mutation-free
     */
    private static function realReturnType(Codebase $codebase, string $fqClasslikeName, string $methodNameLower): ?Union
    {
        return self::storage($codebase, $fqClasslikeName)?->methods[$methodNameLower]->return_type ?? null;
    }

    /**
     * @param lowercase-string $methodNameLower
     * @psalm-mutation-free
     */
    private static function pseudoReturnType(Codebase $codebase, string $canonicalFacade, string $methodNameLower): ?Union
    {
        return self::storage($codebase, $canonicalFacade)?->pseudo_static_methods[$methodNameLower]->return_type ?? null;
    }

    /**
     * @param lowercase-string $methodNameLower
     * @return ?list<FunctionLikeParameter>
     * @psalm-mutation-free
     */
    private static function pseudoMethodParams(Codebase $codebase, string $canonicalFacade, string $methodNameLower): ?array
    {
        return self::storage($codebase, $canonicalFacade)?->pseudo_static_methods[$methodNameLower]->params ?? null;
    }

    /**
     * Null when the class was never scanned — narrowing then stays off and the
     * declared contract type survives untouched.
     *
     * @psalm-mutation-free
     */
    private static function storage(Codebase $codebase, string $fqClasslikeName): ?\Psalm\Storage\ClassLikeStorage
    {
        try {
            return $codebase->classlike_storage_provider->get($fqClasslikeName);
        } catch (\InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Return a copy of the declared union with the expected-contract atomic swapped
     * for the concrete, leaving every other atomic in place. Null when the declared
     * union is absent or does not name the contract — the drift guard: if Laravel's
     * signature changed out from under us, we narrow nothing rather than override a
     * declaration we never verified.
     *
     * @param class-string $expectedContract
     * @param class-string $concrete
     * @psalm-mutation-free
     */
    private static function narrowContract(?Union $declared, string $expectedContract, string $concrete): ?Union
    {
        if (!$declared instanceof Union) {
            return null;
        }

        $expectedContractLc = \strtolower($expectedContract);
        $atomics = [];
        $matched = false;

        foreach ($declared->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TNamedObject && \strtolower($atomic->value) === $expectedContractLc) {
                $atomics[] = new TNamedObject($concrete);
                $matched = true;
            } else {
                $atomics[] = $atomic;
            }
        }

        return $matched ? new Union($atomics) : null;
    }
}
