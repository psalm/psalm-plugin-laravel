<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Application;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use Psalm\Plugin\EventHandler\AfterMethodCallAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterMethodCallAnalysisEvent;

/**
 * Records a synthetic call-graph reference to the `__construct` of every concrete
 * class registered through Laravel's container binding methods.
 *
 * Why: Laravel resolves container-bound classes through `Container::build()`,
 * which reflects on `__construct`. The class name only appears as a string FQCN
 * argument to `bind()`/`singleton()`/`scoped()` etc., never as `new Concrete(...)`
 * in user code, so Psalm's reference graph never picks up the constructor and
 * reports `PossiblyUnusedMethod`. Adding a synthetic
 * {@see \Psalm\Internal\Provider\FileReferenceProvider::addMethodReferenceToClassMember()}
 * entry from the calling site (typically `ServiceProvider::register`) lets the
 * existing unused-method check short-circuit naturally. See psalm/psalm-plugin-laravel#943.
 *
 * Scope:
 *  - Two-arg form: `bind(Interface::class, Concrete::class)` — Concrete recorded.
 *  - Single-arg form: `bind(Concrete::class)` — Concrete recorded.
 *  - Contextual fluent form: `$app->when(X)->needs(Y)->give(Concrete::class)` —
 *    Concrete recorded via ContextualBindingBuilder::give.
 *  - Variadic contextual form: `give([Concrete1::class, Concrete2::class])` —
 *    each class-string item recorded. The Laravel docs document this as the
 *    standard pattern for typed-variadic injection, so the array branch is
 *    only enabled for `give()`; `bind()` is `Closure|string|null` (Laravel
 *    even type-errors on arrays at Container.php:380), so no array branch there.
 *  - Closure / instance second-arg forms are skipped: the closure body or the
 *    pre-built instance already contains the `new` expression that Psalm tracks
 *    naturally, so no synthetic reference is needed.
 *  - `instance()` and `register()` are intentionally NOT here: `instance()`
 *    receives a pre-built object whose construction site is visible to Psalm,
 *    and `register()` always targets a ServiceProvider whose `__construct` is
 *    already suppressed in SuppressHandler via METHOD_LEVEL_BY_PARENT_CLASS.
 *
 * Method-id gate covers both the concrete Container and the contract, matching
 * the dispatch behaviour of {@see OctaneIncompatibleBindingHandler}: the
 * `declaring_method_id` points at whichever one is in scope for the receiver.
 */
final class ContainerBoundConstructorHandler implements AfterMethodCallAnalysisInterface
{
    /**
     * Method names this handler reacts to. Keys are lowercase.
     *
     * `instance` and `register` are intentionally absent — see the class docblock.
     * `give` is included to cover contextual bindings:
     * `$this->app->when(X::class)->needs(Y::class)->give(Concrete::class)` reaches
     * Concrete's `__construct` through the same `Container::build()` reflection path.
     */
    private const BINDING_METHOD_NAMES = [
        'bind' => true,
        'bindif' => true,
        'singleton' => true,
        'singletonif' => true,
        'scoped' => true,
        'scopedif' => true,
        'give' => true,
    ];

    /**
     * Declaring method ids (lowercase) that count as "container binding". Covers both
     * the concrete container and the contract; the user's receiver type determines
     * which the declaring_method_id resolves to.
     *
     * Includes `ContextualBindingBuilder::give` (contract + concrete) because the
     * fluent `$app->when(...)->needs(...)->give(Concrete::class)` chain hands a
     * concrete class-string to `Container::build()` exactly like the direct
     * `bind(...)` family does.
     */
    private const CONTAINER_METHOD_IDS_LOWER = [
        'illuminate\\container\\container::bind' => true,
        'illuminate\\container\\container::bindif' => true,
        'illuminate\\container\\container::singleton' => true,
        'illuminate\\container\\container::singletonif' => true,
        'illuminate\\container\\container::scoped' => true,
        'illuminate\\container\\container::scopedif' => true,
        'illuminate\\contracts\\container\\container::bind' => true,
        'illuminate\\contracts\\container\\container::bindif' => true,
        'illuminate\\contracts\\container\\container::singleton' => true,
        'illuminate\\contracts\\container\\container::singletonif' => true,
        'illuminate\\contracts\\container\\container::scoped' => true,
        'illuminate\\contracts\\container\\container::scopedif' => true,
        'illuminate\\container\\contextualbindingbuilder::give' => true,
        'illuminate\\contracts\\container\\contextualbindingbuilder::give' => true,
    ];

    /** @inheritDoc */
    #[\Override]
    public static function afterMethodCallAnalysis(AfterMethodCallAnalysisEvent $event): void
    {
        $expr = $event->getExpr();

        // The event also fires for StaticCall; facade-form `App::bind(...)` is
        // out of scope here (rare in practice, declaring_method_id wouldn't match
        // anyway because the call routes through __callStatic on the facade).
        if (!$expr instanceof MethodCall) {
            return;
        }

        // Hot-path gate on the cheap method-name token before the lowercased
        // FQN id, mirroring OctaneIncompatibleBindingHandler. The handler fires
        // for every resolved MethodCall in the codebase; >99% are not bindings.
        if (!$expr->name instanceof Identifier) {
            return;
        }

        $methodNameLower = \strtolower($expr->name->name);

        if (!isset(self::BINDING_METHOD_NAMES[$methodNameLower])) {
            return;
        }

        if (!isset(self::CONTAINER_METHOD_IDS_LOWER[\strtolower($event->getDeclaringMethodId())])) {
            return;
        }

        $args = $expr->getArgs();
        $argCount = \count($args);

        if ($argCount === 0) {
            return;
        }

        // Concrete-target extraction:
        //  - two-arg form (bind/singleton/scoped + If variants): second arg is the
        //    concrete; first is the abstract/interface
        //  - single-arg form (bind(C::class), give(C::class)): first arg is both
        //    abstract AND concrete
        //  - variadic give([C1::class, C2::class]): array of concretes (give() only)
        // If the second arg is a closure/array/variable for the bind() family, the
        // concrete is dynamic and Psalm tracks it through the closure body naturally.
        $targetNode = $argCount >= 2 ? $args[1]->value : $args[0]->value;
        $targetFqcns = self::extractTargetFqcns($targetNode, $methodNameLower);

        if ($targetFqcns === []) {
            return;
        }

        $codebase = $event->getCodebase();
        $callingMethodId = $event->getContext()->calling_method_id;
        $sourceFilePath = $event->getStatementsSource()->getFilePath();
        $referenceProvider = $codebase->file_reference_provider;

        foreach ($targetFqcns as $targetFqcn) {
            // Only suppress when the target's `__construct` is public. Laravel's container
            // builds instances via `ReflectionClass::newInstanceArgs()`, which throws on
            // non-public constructors — so a `private` or `protected` `__construct` on a
            // bound class is a real runtime bug, not a false positive. Mirrors the
            // visibility filter SuppressHandler::suppressFrameworkHookMethod() applies
            // to `Mailable::__construct` and other framework-dispatched hooks.
            if (!self::targetHasPublicConstructor($codebase, $targetFqcn)) {
                continue;
            }

            $referencedConstructorId = \strtolower($targetFqcn) . '::__construct';

            // `calling_method_id` is null for top-level code (rare in service providers,
            // since binds usually live inside register()/boot()). Fall back to a file-level
            // reference so the synthetic edge survives either way.
            if ($callingMethodId !== null) {
                $referenceProvider->addMethodReferenceToClassMember(
                    $callingMethodId,
                    $referencedConstructorId,
                    false,
                );

                continue;
            }

            $referenceProvider->addFileReferenceToClassMember(
                $sourceFilePath,
                $referencedConstructorId,
                false,
            );
        }
    }

    /**
     * Resolve the list of concrete FQCNs from the target arg of a binding call.
     *
     * For every binding method except `give`, returns at most one FQCN (extracted from
     * a `Foo::class` constant fetch) or an empty list. For `give`, additionally accepts
     * an `Array_` literal whose every item value is `Foo::class`, returning the list
     * of resolved FQCNs — this is Laravel's documented pattern for typed-variadic
     * contextual binding (`->give([NullFilter::class, ProfanityFilter::class])`).
     * If any item in the array isn't a class-const fetch (closure, variable, string
     * literal), the entire array is treated as dynamic and an empty list is returned.
     *
     * @return list<string>
     */
    private static function extractTargetFqcns(Node $node, string $methodNameLower): array
    {
        $singleFqcn = self::extractClassConstFqcn($node);

        if ($singleFqcn !== null) {
            return [$singleFqcn];
        }

        // Array form is only meaningful for `give()`'s variadic shape.
        if ($methodNameLower !== 'give' || !$node instanceof Array_) {
            return [];
        }

        $fqcns = [];

        foreach ($node->items as $item) {
            // PhpParser models trailing commas / empty slots as `null` items. We're
            // not parsing a list comprehension here, so a null slot means "give up,
            // bail to dynamic" — same all-or-nothing rule as below.
            if ($item === null) {
                return [];
            }

            // Skip spread (`...$classes`) and other non-literal item shapes; we can't
            // statically enumerate them, and partial coverage would create surprising
            // asymmetry. Same all-or-nothing rule as the OctaneIncompatibleBindingHandler
            // applies to closure introspection.
            if ($item->unpack) {
                return [];
            }

            $itemFqcn = self::extractClassConstFqcn($item->value);

            if ($itemFqcn === null) {
                return [];
            }

            $fqcns[] = $itemFqcn;
        }

        return $fqcns;
    }

    /**
     * Look up the target's `__construct` MethodStorage and check it is public.
     *
     * Returns true when the class either has no declared `__construct` (the default
     * public no-arg constructor is fine for Container reflection) or has one declared
     * as `public`. Returns false for `private`/`protected` constructors, and for any
     * lookup failure (target not yet scanned, class not in the codebase) — better to
     * leave the issue surfaced than to silently mask it.
     *
     * @psalm-mutation-free
     */
    private static function targetHasPublicConstructor(\Psalm\Codebase $codebase, string $targetFqcn): bool
    {
        try {
            $storage = $codebase->classlike_storage_provider->get($targetFqcn);
        } catch (\InvalidArgumentException) {
            // Target wasn't scanned (out-of-tree reference, typo, etc.). The
            // PossiblyUnusedMethod check itself won't fire on something Psalm
            // can't see, so the conservative answer is "don't add a synthetic
            // reference we can't justify".
            return false;
        }

        $constructorStorage = $storage->methods['__construct'] ?? null;

        if ($constructorStorage === null) {
            // No explicit constructor — Container builds it without parameters via the
            // implicit public no-arg form. PossiblyUnusedMethod doesn't fire on absent
            // methods anyway, so the answer is "safe to proceed" (no-op downstream).
            return true;
        }

        return $constructorStorage->visibility === \Psalm\Internal\Analyzer\ClassLikeAnalyzer::VISIBILITY_PUBLIC;
    }

    /**
     * Extract the resolved FQCN from a `Foo::class` constant fetch.
     *
     * Returns null for any other expression shape (closures, variables, string
     * literals, dynamic class constants). String-literal FQCNs (`bind('App\Foo')`)
     * are intentionally not supported: they're rare and surfacing them would
     * require namespace-aware resolution we don't otherwise need.
     */
    private static function extractClassConstFqcn(Node $node): ?string
    {
        if (!$node instanceof ClassConstFetch
            || !$node->class instanceof Name
            || !$node->name instanceof Identifier
            || \strtolower($node->name->name) !== 'class'
        ) {
            return null;
        }

        // Psalm's SimpleNameResolver sets `resolvedName` as a string before storing,
        // so under Psalm analysis the attribute is always `?string`. Matches the
        // pattern used by OctaneIncompatibleBindingHandler::literalAbstractFrom().
        /** @psalm-var ?string $resolved */
        $resolved = $node->class->getAttribute('resolvedName');

        return \is_string($resolved) ? $resolved : null;
    }
}
