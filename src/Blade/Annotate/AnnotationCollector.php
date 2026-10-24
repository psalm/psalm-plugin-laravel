<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade\Annotate;

use Illuminate\View\ViewName;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\Internal\Analyzer\ProjectAnalyzer;
use Psalm\LaravelPlugin\Blade\PreludeBuilder;
use Psalm\LaravelPlugin\Handlers\Views\ViewCallChain;
use Psalm\LaravelPlugin\Handlers\Views\ViewContractHandler;
use Psalm\LaravelPlugin\Internal\Arg as ArgUtil;
use Psalm\LaravelPlugin\Internal\LateStatic;
use Psalm\Plugin\EventHandler\AfterStatementAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterStatementAnalysisEvent;
use Psalm\StatementsSource;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\TypeNode;
use Psalm\Type\TypeVisitor;
use Psalm\Type\Union;

/**
 * Records, per view name, the type every resolved `view()` call site and compiled include passes
 * for each data key — the only sound type source {@see AnnotationWriter} has.
 *
 * Registered only in annotate mode, which pins the analysis to one process: Psalm's forked analysis
 * workers never hand their statics back to the parent, where the write runs.
 *
 * The confidence rule is deliberately narrow, because a wrong annotation becomes a false positive
 * at every call site that renders the template. A type is answered only when every producer of that
 * view agreed on it, or disagreed in a way one declaration covers ({@see self::merged()}), AND no
 * producer left the view's data set open — an open set can carry the same key with another type,
 * invisibly.
 *
 * @internal
 */
final class AnnotationCollector implements AfterStatementAnalysisInterface
{
    /**
     * Beyond three classes a union stops reading as a contract and starts reading as a call site
     * passing the wrong thing.
     */
    private const MAX_MERGED_ATOMICS = 3;

    private static bool $enabled = false;

    /**
     * Keyed by view name then variable name, then the distinct types passed for it by id. `false`
     * marks a key whose passed type has no declaration a template can carry.
     *
     * @var array<string, array<string, array<string, Union>|false>>
     */
    private static array $observed = [];

    /** @var array<string, true> views at least one producer left an open data set for, or could not be read at all */
    private static array $open = [];

    /**
     * Per view, the names EVERY provably-closed producer passed — the intersection, narrowing with
     * each one. Absent until the first closed producer. A name missing from it is one some call site
     * legitimately omits, so declaring it would report MissingViewData at that very call site.
     *
     * @var array<string, array<string, true>>
     */
    private static array $alwaysSupplied = [];

    /**
     * Whether the statement hook ran at all in THIS process. Psalm forks its analysis workers once
     * the project is big enough, and a worker's statics never reach the parent that runs
     * AfterAnalysis — where, seeing nothing collected, the writer would report every variable of
     * every template untyped. False in the parent of a forked run is what discriminates it.
     */
    private static bool $analyzed = false;

    /**
     * The calls of every chain already recorded: its binding call and the `with()` links folded into
     * its data. A callback's statements are analysed, and recorded, before the statement holding the
     * callback completes, whose scan for nested view names then meets the same call nodes again.
     * Weak, so the analysed ASTs are not kept alive.
     *
     * @var \WeakMap<Expr, true>|null
     */
    private static ?\WeakMap $recorded = null;

    public static function init(): void
    {
        self::$enabled = true;
    }

    public static function reset(): void
    {
        self::$enabled = false;
        self::$observed = [];
        self::$open = [];
        self::$alwaysSupplied = [];
        self::$analyzed = false;
        self::$recorded = null;
    }

    /**
     * Called for every analysed statement, ahead of any filtering: the question it answers is "did
     * analysis happen in this process", not "was a view() call found" — a project with no `view()`
     * call at all is legitimate, a parent that analysed nothing is not.
     */
    public static function markAnalyzed(): void
    {
        self::$analyzed = true;
    }

    public static function sawAnalysis(): bool
    {
        return self::$analyzed;
    }

    #[\Override]
    public static function afterStatementAnalysis(AfterStatementAnalysisEvent $event): ?bool
    {
        if (!self::$enabled) {
            return null;
        }

        self::markAnalyzed();

        // The same statement shapes, and the same outermost-first walk, that
        // {@see ViewContractHandler} checks call sites with.
        $stmt = $event->getStmt();

        if ($stmt instanceof Stmt\Echo_) {
            self::recordEcho($stmt, $event->getStatementsSource());

            return null;
        }

        $expr = match (true) {
            $stmt instanceof Stmt\Expression, $stmt instanceof Stmt\Return_ => $stmt->expr,
            default => null,
        };

        if (!$expr instanceof Expr) {
            return null;
        }

        $chain = ViewCallChain::from($expr, $event->getStatementsSource());

        $skip = [];

        if ($chain instanceof ViewCallChain) {
            self::record($chain->viewName, $chain->supplied(), $chain->complete);
            /** @psalm-var \WeakMap<Expr, true> $recorded a fresh WeakMap infers as WeakMap<object, mixed> */
            $recorded = self::$recorded ?? new \WeakMap();

            for ($link = $expr; $link instanceof Expr; $link = self::innerLink($link)) {
                $recorded[$link] = true;
            }

            self::$recorded = $recorded;
            $skip = self::spine($expr);
        }

        // The chain declines any shape it does not fully understand — `$view = view('home', [...])`
        // among them, since the walk starts at the outermost expression. Its data would otherwise be
        // invisible, and a disagreement with it would read as agreement. A recorded chain can still
        // nest another render in its data (`view('a', ['x' => view('a', [...])->render()])`).
        foreach (self::viewNamesIn($expr, $skip) as $viewName) {
            self::markUnreadable($viewName);
        }

        return null;
    }

    /**
     * A compiled include is a producer of the view it renders like any `view()` call: left unseen, a
     * controller's type gets declared that the include then passes something else for. One that
     * passes the including scope (`@include`, `@extends`) leaves the data set open.
     */
    private static function recordEcho(Stmt\Echo_ $stmt, StatementsSource $source): void
    {
        $echoed = $stmt->exprs[0] ?? null;

        // Blade compiles a bare `render()`; an author's `render($callback)` can render the view again.
        $include = $echoed instanceof Expr\MethodCall && self::methodName($echoed) === 'render' && $echoed->args !== []
            ? null
            : ViewContractHandler::compiledInclude($stmt);
        $chain = $include instanceof Expr\MethodCall ? ViewCallChain::fromInclude($include, $source) : null;
        $skip = [];

        if ($chain instanceof ViewCallChain && $echoed instanceof Expr) {
            self::record($chain->viewName, $chain->supplied(), $chain->complete);
            $skip = self::spine($echoed);
        }

        // Matching `make`/`view`/`markdown` on any receiver here only costs coverage (a view marked
        // open), never a wrong declaration.
        foreach ($stmt->exprs as $expr) {
            foreach (self::viewNamesIn($expr, $skip) as $viewName) {
                self::markUnreadable($viewName);
            }
        }
    }

    /**
     * The calls of a recorded chain, receiver-wards from the outermost: the producer already
     * recorded, which the nested-render scan must not mark open again.
     *
     * @return array<int, true> keyed by spl_object_id()
     *
     * @psalm-capabilities read-props
     */
    private static function spine(Expr $expr): array
    {
        $spine = [];

        while ($expr instanceof Expr\MethodCall || $expr instanceof Expr\NullsafeMethodCall) {
            $spine[\spl_object_id($expr)] = true;
            $expr = $expr->var;
        }

        $spine[\spl_object_id($expr)] = true;

        return $spine;
    }

    /**
     * Literal view names of every rendering call nested in an expression the chain declined (or nested
     * in a recorded chain's data), minus
     * the calls a callback's own statement already recorded, unless the expression may write into the
     * view such a callback returns. Over-marking is the safe direction: the worst it costs is a name
     * left undeclared where a type was provable.
     *
     * @param array<int, true> $skip calls (by spl_object_id()) already recorded as a producer
     *
     * @return list<string>
     */
    private static function viewNamesIn(Expr $expr, array $skip = []): array
    {
        $names = [];
        $writes = null;

        foreach ((new NodeFinder())->find($expr, self::isViewBinder(...)) as $call) {
            if (!$call instanceof Node\Expr\CallLike || $call->isFirstClassCallable() || isset($skip[\spl_object_id($call)])) {
                continue;
            }

            if (isset(self::$recorded[$call]) && !($writes ??= self::mayWriteViewData($expr))) {
                continue;
            }

            foreach (self::viewNameExprs($call) as $candidate) {
                // Marked under the name Laravel renders, which a spelling the chain declined
                // (`'pkg::x::y'`, `' x '`) does not carry verbatim.
                if ($candidate instanceof String_ && $candidate->value !== '') {
                    $names[] = \trim(ViewName::normalize($candidate->value));
                }
            }
        }

        return $names;
    }

    /**
     * The expressions a rendering call may take its view name from.
     *
     * @return list<Expr|null>
     */
    private static function viewNameExprs(Expr\CallLike $call): array
    {
        $args = \array_values($call->getArgs());

        // By name too: `view(data: [...], view: 'home')` puts the view name anywhere.
        $arg = static function (int $position, string $name) use ($args): ?Expr {
            $found = ArgUtil::byNameOrPosition($args, $position, $name);

            return $found instanceof Node\Arg && !$found->unpack ? $found->value : null;
        };

        $exprs = match ($call instanceof Expr\FuncCall ? 'view' : self::methodName($call)) {
            'renderwhen', 'renderunless' => [$arg(1, 'view')],
            'first' => [$arg(0, 'views')],
            'startcomponentfirst' => [$arg(0, 'names')],
            // `@each` renders its empty view with no data; a `raw|` one there is text, not a view.
            'rendereach' => [$arg(0, 'view'), self::unlessRaw($arg(3, 'empty'))],
            'resolve' => [self::anonymousComponentView($arg(0, 'data'))],
            default => [$arg(0, 'view')],
        };

        $flat = [];

        // `first()` renders whichever candidate exists, so every literal one may be the view.
        foreach ($exprs as $expr) {
            if (!$expr instanceof Expr\Array_) {
                $flat[] = $expr;

                continue;
            }

            foreach ($expr->items as $item) {
                $flat[] = $item?->value;
            }
        }

        return $flat;
    }

    /** @psalm-capabilities read-props */
    private static function unlessRaw(?Expr $expr): ?Expr
    {
        return $expr instanceof String_ && \str_starts_with($expr->value, 'raw|') ? null : $expr;
    }

    /**
     * The `'view' => '...'` entry of the data an anonymous `<x-...>` tag compiles to.
     *
     * @psalm-capabilities read-props
     */
    private static function anonymousComponentView(?Expr $data): ?Expr
    {
        // `resolve([...] + (isset($attributes) ? $attributes->all() : []))`
        if ($data instanceof Expr\BinaryOp\Plus) {
            $data = $data->left;
        }

        if (!$data instanceof Expr\Array_) {
            return null;
        }

        foreach ($data->items as $item) {
            $key = $item?->key;

            if ($item !== null && $key instanceof String_ && $key->value === 'view') {
                return $item->value;
            }
        }

        return null;
    }

    /**
     * Whether the expression holds anything that can put data into a view object it was handed:
     * a `with*()` call (`View::__call()` turns `withTitle()` into `with('title')`), `nest()`, the
     * `ArrayAccess` and `__set()` writes, or a call whose name is not literal. The recorded data of
     * a callback's view is only final when nothing around the callback can change it; the recorded
     * chain's own `with()` links are already part of it.
     */
    private static function mayWriteViewData(Expr $expr): bool
    {
        return (new NodeFinder())->findFirst($expr, static function (Node $node): bool {
            if ($node instanceof Expr && isset(self::$recorded[$node])) {
                return false;
            }

            if ($node instanceof Expr\MethodCall || $node instanceof Expr\NullsafeMethodCall || $node instanceof Expr\StaticCall) {
                if (!$node->name instanceof Node\Identifier) {
                    return true;
                }

                $name = $node->name->toLowerString();

                return \str_starts_with($name, 'with') || \in_array($name, ['nest', 'offsetset', '__set'], true);
            }

            // `$view['title'] = 42` and `$view->title = 42`, destructuring into either included.
            return ($node instanceof Expr\Assign || $node instanceof Expr\AssignRef || $node instanceof Expr\AssignOp)
                && !$node->var instanceof Expr\Variable;
        }) instanceof Node;
    }

    /** The next call towards a recorded chain's binding call, or null once the binding call is reached. */
    private static function innerLink(Expr $link): ?Expr
    {
        return ($link instanceof Expr\MethodCall || $link instanceof Expr\NullsafeMethodCall)
            && $link->name instanceof Node\Identifier
            && \in_array($link->name->toLowerString(), ['with', 'witherrors'], true)
            ? $link->var
            : null;
    }

    private static function isViewBinder(Node $node): bool
    {
        if ($node instanceof Expr\FuncCall) {
            return $node->name instanceof Node\Name && $node->name->toLowerString() === 'view';
        }

        if (!$node instanceof Expr\MethodCall
            && !$node instanceof Expr\NullsafeMethodCall
            && !$node instanceof Expr\StaticCall
        ) {
            return false;
        }

        $method = self::methodName($node);

        if (\in_array($method, ['make', 'view', 'markdown'], true)) {
            return true;
        }

        if ($node instanceof Expr\StaticCall) {
            return $method === 'resolve'
                && $node->class instanceof Node\Name
                && \strtolower(\ltrim($node->class->toString(), '\\')) === 'illuminate\\view\\anonymouscomponent';
        }

        // The other Factory methods compiled includes and components call (`@includeFirst`,
        // `@includeWhen`, `@each`, `@component`), too common as names to match on any receiver.
        return $node instanceof Expr\MethodCall
            && $node->var instanceof Expr\Variable
            && $node->var->name === '__env'
            && \in_array($method, ['first', 'renderwhen', 'renderunless', 'rendereach', 'startcomponent', 'startcomponentfirst'], true);
    }

    private static function methodName(Node $call): ?string
    {
        return ($call instanceof Expr\MethodCall || $call instanceof Expr\NullsafeMethodCall || $call instanceof Expr\StaticCall)
            && $call->name instanceof Node\Identifier
                ? $call->name->toLowerString()
                : null;
    }

    /** A producer of this view whose contribution could not be read at all. */
    public static function markUnreadable(string $viewName): void
    {
        self::$open[self::canonical($viewName)] = true;
    }

    /** `cards/inner` and `cards.inner` name one template: the view finder normalizes the slash. */
    private static function canonical(string $viewName): string
    {
        return ViewName::normalize($viewName);
    }

    /**
     * @param array<string, Union> $data     supplied variable name => the type passed for it
     * @param bool                 $complete whether the producer's key set was proven closed
     */
    public static function record(string $viewName, array $data, bool $complete): void
    {
        $viewName = self::canonical($viewName);

        if ($complete) {
            $keys = \array_fill_keys(\array_keys($data), true);

            self::$alwaysSupplied[$viewName] = isset(self::$alwaysSupplied[$viewName])
                ? \array_intersect_key(self::$alwaysSupplied[$viewName], $keys)
                : $keys;
        } else {
            self::markUnreadable($viewName);
        }

        foreach ($data as $name => $type) {
            $seen = self::$observed[$viewName][$name] ?? null;

            if ($seen === false) {
                continue;
            }

            if (!self::dropsLateStaticSafely($type)) {
                self::$observed[$viewName][$name] = false;

                continue;
            }

            $type = self::withoutUnboundedIntRange(LateStatic::strip($type));
            $seen ??= [];
            $key = $type->getId(false);
            // Literals sharing an id (`1`, `100`) are combined, not replaced: the merge's containment
            // check must see every value a call site passed.
            $seen[$key] = isset($seen[$key]) ? Type::combineUnionTypes($seen[$key], $type) : $type;
            self::$observed[$viewName][$name] = $seen;
        }
    }

    /**
     * The distinct types the producers of a view pass for a name, when they disagree in a way no
     * declaration covers: what the user is left to declare by hand. Null otherwise, an open data set
     * included (that is no disagreement, just an unknown).
     *
     * @return list<string>|null
     */
    public static function disagreeingTypes(string $viewName, string $name): ?array
    {
        $distinct = self::$observed[$viewName][$name] ?? null;

        if (isset(self::$open[$viewName]) || !\is_array($distinct) || \count($distinct) < 2 || self::merged($distinct) instanceof Union) {
            return null;
        }

        $ids = \array_map(strval(...), \array_keys($distinct));
        \sort($ids);

        return $ids;
    }

    /**
     * The type to declare for one variable, or null when nothing was proven and the caller should
     * leave it undeclared.
     */
    public static function typeFor(string $viewName, string $name): ?string
    {
        $viewName = self::canonical($viewName);

        if (isset(self::$open[$viewName])) {
            return null;
        }

        $distinct = self::$observed[$viewName][$name] ?? null;

        if (!\is_array($distinct)) {
            return null;
        }

        $observed = \count($distinct) === 1 ? \reset($distinct) : self::merged($distinct);

        if (!$observed instanceof Union) {
            return null;
        }

        // A type that only means what it means where it was inferred — a template parameter, a
        // conditional — would be read back in the template as something else entirely. `T` parses
        // fine; it just parses as a class named T.
        if (self::isContextDependent($observed)) {
            return null;
        }

        // Literal precision is dropped on purpose: two call sites passing 'draft' and 'published'
        // describe one `string` contract, and pinning either would be wrong for the other.
        $id = self::withAbsoluteClassNames($observed->getId(false));

        // The id is about to be written inside a docblock. An id carrying the terminator
        // (a literal array key can) would close the docblock early and leave the rest of the
        // declaration as PHP in the template.
        if (\str_contains($id, '*/') || \preg_match('/[\r\n]/', $id) === 1) {
            return null;
        }

        // The template comment is read back by Psalm on the next run. A type whose id does not
        // survive that round trip is no annotation at all. Any throwable counts: the fallback is
        // no declaration, which is never wrong.
        try {
            Type::parseString($id);
        } catch (\Throwable) {
            return null;
        }

        return $id;
    }

    /**
     * One type for producers that disagree, or null when they disagree too much to share one.
     *
     * Merged: a difference in nullability only; one scalar kind (`true` and `false` give `bool`); up
     * to three classes, `null` not counted. Psalm keeps one class with different generic params apart
     * (`Collection<int, Post>|Collection<int, Video>`), which holds even where the template parameter
     * is invariant, so each counts as a class. Never merged: a `mixed` producer, two scalar kinds, an
     * array that differs by more than nullability, an array or a scalar with an object. Each usually
     * means one call site passes the wrong thing. Never widened to a common parent either: which one
     * is ambiguous, and members only a subclass has would turn into UndefinedMethod.
     *
     * A merge that is not a plain union of what was passed (`bool` from `true`) must still be proven
     * to hold at every call site; unproven declines.
     *
     * @param array<string, Union> $distinct
     */
    private static function merged(array $distinct): ?Union
    {
        // Sorted, so the verdict cannot depend on which call site was analysed first.
        \ksort($distinct);
        $withoutNull = [];

        foreach ($distinct as $type) {
            if ($type->hasMixed()) {
                return null;
            }

            $atomics = \array_filter($type->getAtomicTypes(), static fn(Atomic $atomic): bool => !$atomic instanceof Atomic\TNull);

            if ($atomics !== []) {
                $withoutNull[(new Union(\array_values($atomics)))->getId(false)] = true;
            }
        }

        $types = \array_values($distinct);

        if ($types === []) {
            return null;
        }

        $combined = Type::combineUnionTypeArray($types, null);

        if (\count($withoutNull) <= 1) {
            return $combined;
        }

        $atomics = \array_filter($combined->getAtomicTypes(), static fn(Atomic $atomic): bool => !$atomic instanceof Atomic\TNull);
        $objects = \array_filter($atomics, static fn(Atomic $atomic): bool => $atomic instanceof Atomic\TNamedObject);
        $first = \reset($atomics);

        $mergeable = \count($atomics) === 1
            ? $first instanceof Atomic\TNamedObject || $first instanceof Atomic\Scalar
            : \count($objects) === \count($atomics) && \count($atomics) <= self::MAX_MERGED_ATOMICS;

        return $mergeable && self::containsEvery($combined, $distinct) ? $combined : null;
    }

    /**
     * Whether every producer's type is contained by the merged one. An atomic the merge kept verbatim
     * needs no proof; anything else asks the codebase, the same check call sites are held to.
     *
     * @param array<string, Union> $distinct
     */
    private static function containsEvery(Union $merged, array $distinct): bool
    {
        $kept = [];

        foreach ($merged->getAtomicTypes() as $atomic) {
            $kept[$atomic->getId()] = true;
        }

        foreach ($distinct as $type) {
            foreach ($type->getAtomicTypes() as $atomic) {
                if (!isset($kept[$atomic->getId()])) {
                    if (!self::isContained($type, $merged)) {
                        return false;
                    }

                    continue 2;
                }
            }
        }

        return true;
    }

    /** Unproven (no project analyzer, an unknown class) counts as not contained. */
    private static function isContained(Union $type, Union $container): bool
    {
        try {
            return ProjectAnalyzer::getInstance()->getCodebase()->isTypeContainedByType($type, $container);
        } catch (\InvalidArgumentException|UnpopulatedClasslikeException|\RuntimeException|\Error) {
            return false;
        }
    }

    /**
     * Whether some provably-closed call site renders this view WITHOUT the name — which proves the
     * template can be rendered without it, so declaring it would manufacture a MissingViewData
     * at that call site. False while no call site has proven its key set closed.
     */
    public static function isOptional(string $viewName, string $name): bool
    {
        $supplied = self::$alwaysSupplied[self::canonical($viewName)] ?? null;

        return $supplied !== null && !isset($supplied[$name]);
    }

    /**
     * `getId()` prints a class without its leading `\`, which a docblock in a template resolves through
     * the template's own `use` imports (`App\Models\User` under `use Other\Models as App` means another
     * class). The declaration is written absolute, so it means the class it was inferred from and keeps
     * being type-checked at call sites.
     */
    private static function withAbsoluteClassNames(string $id): string
    {
        $names = \array_unique(\array_filter(
            PreludeBuilder::classNamesIn($id) ?? [],
            static fn(string $name): bool => !\str_starts_with($name, '\\'),
        ));

        if ($names === []) {
            return $id;
        }

        \usort($names, static fn(string $a, string $b): int => \strlen($b) <=> \strlen($a));
        $alternatives = \implode('|', \array_map(static fn(string $name): string => \preg_quote($name, '/'), $names));

        // Quoted literals are skipped whole; a shape key (`name:`) or a `$parameter` is no class.
        return (string) \preg_replace_callback(
            "/'(?:[^'\\\\]|\\\\.)*'|\"(?:[^\"\\\\]|\\\\.)*\"|(?<![\\w\\\\\$-])(?:{$alternatives})(?![\\w\\\\])(?!\\s*\\??:(?!:))/",
            static fn(array $match): string => $match[0][0] === "'" || $match[0][0] === '"' ? $match[0] : '\\' . $match[0],
            $id,
        );
    }

    /**
     * `int<min, max>` is plain `int`, at any depth (a difference of two counts infers it). Normalized
     * before the agreement check, so `int` and `int<min, max>` from two call sites agree.
     */
    private static function withoutUnboundedIntRange(Union $union): Union
    {
        $visitor = new class extends MutableTypeVisitor {
            /**
             * @psalm-capabilities read-props|write-this-props|write-refs
             */
            #[\Override]
            protected function enterNode(TypeNode &$type): ?int
            {
                if ($type instanceof Atomic\TIntRange && $type->min_bound === null && $type->max_bound === null) {
                    $type = new Atomic\TInt($type->from_docblock);
                }

                return null;
            }
        };

        $visitor->traverse($union);

        return $union;
    }

    /**
     * True for a type carrying a template parameter or a conditional, at any depth.
     *
     * @psalm-pure
     */
    private static function isContextDependent(Union $union): bool
    {
        return self::anyAtomic($union, static fn(Atomic $atomic): bool => $atomic instanceof Atomic\TTemplateParam
            || $atomic instanceof Atomic\TTemplateParamClass
            || $atomic instanceof Atomic\TTemplateIndexedAccess
            || $atomic instanceof Atomic\TTemplateKeyOf
            || $atomic instanceof Atomic\TTemplatePropertiesOf
            || $atomic instanceof Atomic\TTemplateValueOf
            || $atomic instanceof Atomic\TConditional);
    }

    /** @psalm-pure */
    private static function carriesLateStatic(Union $union): bool
    {
        return self::anyAtomic($union, static fn(Atomic $atomic): bool => $atomic instanceof Atomic\TNamedObject && $atomic->is_static);
    }

    /**
     * Whether any atomic in the type matches, at any depth. `get_class($x)` keeps the type of `$x` in
     * `TDependentGetClass::$as_type`, which Psalm's visitor does not descend into, so this does.
     *
     * @param \Closure[pure](Atomic): bool $matches
     *
     * @psalm-pure
     */
    private static function anyAtomic(Union $union, \Closure $matches): bool
    {
        $visitor = new class ($matches) extends TypeVisitor {
            public bool $found = false;

            /** @param \Closure[pure](Atomic): bool $matches */
            public function __construct(private readonly \Closure $matches) {}

            /** @psalm-external-mutation-free */
            #[\Override]
            protected function enterNode(TypeNode $type): ?int
            {
                if ($type instanceof Atomic && ($this->matches)($type)) {
                    $this->found = true;
                } elseif ($type instanceof Atomic\TDependentGetClass) {
                    $this->traverse($type->as_type);
                }

                return $this->found ? self::STOP_TRAVERSAL : null;
            }
        };

        $visitor->traverse($union);

        return $visitor->found;
    }

    /**
     * Whether dropping every `&static` in the type only widens it. That holds where the marker sits
     * in a covariant position: the top level, an array or iterable, a class-string, a callable's
     * return, or a template parameter the class declares covariant. In a callable parameter the
     * marker-free type promises to accept more; in an invariant template parameter Psalm rejects
     * `Foo&static` against `Foo`. Either way the declaration would contradict the call site, so the
     * name is left undeclared instead.
     *
     * @psalm-capabilities read-props
     */
    private static function dropsLateStaticSafely(Union $union): bool
    {
        foreach ($union->getAtomicTypes() as $atomic) {
            if (!self::dropsLateStaticSafelyIn($atomic)) {
                return false;
            }
        }

        return true;
    }

    /** @psalm-capabilities read-props */
    private static function dropsLateStaticSafelyIn(Atomic $atomic): bool
    {
        if ($atomic instanceof Atomic\TCallable || $atomic instanceof Atomic\TClosure) {
            foreach ($atomic->params ?? [] as $param) {
                if ($param->type instanceof Union && self::carriesLateStatic($param->type)) {
                    return false;
                }
            }

            return !$atomic->return_type instanceof Union || self::dropsLateStaticSafely($atomic->return_type);
        }

        if ($atomic instanceof Atomic\TGenericObject) {
            foreach ($atomic->type_params as $offset => $param) {
                if (self::carriesLateStatic($param)
                    && (!self::isCovariantTemplate($atomic->value, $offset) || !self::dropsLateStaticSafely($param))
                ) {
                    return false;
                }
            }

            return true;
        }

        if ($atomic instanceof Atomic\TArray || $atomic instanceof Atomic\TIterable) {
            return self::allDropLateStaticSafely($atomic->type_params);
        }

        if ($atomic instanceof Atomic\TKeyedArray) {
            return self::allDropLateStaticSafely([...\array_values($atomic->properties), ...($atomic->fallback_params ?? [])]);
        }

        if ($atomic instanceof Atomic\TDependentGetClass) {
            return self::dropsLateStaticSafely($atomic->as_type);
        }

        // A plain object (its own marker) and a class-string of one are covariant in that class.
        if ($atomic instanceof Atomic\TNamedObject || $atomic instanceof Atomic\TClassString) {
            return true;
        }

        return !self::carriesLateStatic(new Union([$atomic]));
    }

    /**
     * @param array<Union> $types
     *
     * @psalm-capabilities read-props
     */
    private static function allDropLateStaticSafely(array $types): bool
    {
        foreach ($types as $type) {
            if (!self::dropsLateStaticSafely($type)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Unproven (an unknown class, storage not populated, no project analyzer) counts as not covariant.
     *
     * @psalm-capabilities read-props
     */
    private static function isCovariantTemplate(string $class, int $offset): bool
    {
        try {
            $storage = ProjectAnalyzer::getInstance()->getCodebase()->classlike_storage_provider->get($class);
        } catch (\InvalidArgumentException|UnpopulatedClasslikeException|\RuntimeException|\Error) {
            return false;
        }

        return $storage->template_covariants[$offset] ?? false;
    }
}
