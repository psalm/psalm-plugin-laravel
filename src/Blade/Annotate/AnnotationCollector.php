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
use Psalm\LaravelPlugin\Internal\Arg as ArgUtil;
use Psalm\Plugin\EventHandler\AfterStatementAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterStatementAnalysisEvent;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\MutableTypeVisitor;
use Psalm\Type\TypeNode;
use Psalm\Type\TypeVisitor;
use Psalm\Type\Union;

/**
 * Records, per view name, the type every resolved `view()` call site passes for each data key —
 * the only sound type source {@see AnnotationWriter} has.
 *
 * Registered only in annotate mode, which pins the analysis to one process: Psalm's forked analysis
 * workers never hand their statics back to the parent, where the write runs.
 *
 * The confidence rule is deliberately narrow, because a wrong annotation becomes a false positive
 * at every call site that renders the template. A type is answered only when every producer of that
 * view agreed on it AND no producer left the view's data set open — an open set can carry the same
 * key with another type, invisibly.
 *
 * @internal
 */
final class AnnotationCollector implements AfterStatementAnalysisInterface
{
    private static bool $enabled = false;

    /**
     * Keyed by view name then variable name. `false` marks a key two producers disagreed on, or one
     * whose passed type has no declaration a template can carry.
     *
     * @var array<string, array<string, Union|false>>
     */
    private static array $observed = [];

    /** @var array<string, true> views at least one producer left an open data set for, or could not be read at all */
    private static array $open = [];

    /**
     * Per view, the names EVERY provably-closed producer passed — the intersection, narrowing with
     * each one. Absent until the first closed producer. A name missing from it is one some call site
     * legitimately omits, so declaring it would report MissingViewVariable at that very call site.
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

        // The same two statement shapes, and the same outermost-first walk, that
        // {@see \Psalm\LaravelPlugin\Handlers\Views\ViewContractHandler} checks call sites with.
        $stmt = $event->getStmt();

        $expr = match (true) {
            $stmt instanceof Stmt\Expression, $stmt instanceof Stmt\Return_ => $stmt->expr,
            default => null,
        };

        if (!$expr instanceof Expr) {
            return null;
        }

        $chain = ViewCallChain::from($expr, $event->getStatementsSource());

        if ($chain instanceof ViewCallChain) {
            self::record($chain->viewName, $chain->supplied(), $chain->complete);
            /** @psalm-var \WeakMap<Expr, true> $recorded a fresh WeakMap infers as WeakMap<object, mixed> */
            $recorded = self::$recorded ?? new \WeakMap();

            for ($link = $expr; $link instanceof Expr; $link = self::innerLink($link)) {
                $recorded[$link] = true;
            }

            self::$recorded = $recorded;

            return null;
        }

        // The chain declines any shape it does not fully understand — `$view = view('home', [...])`
        // among them, since the walk starts at the outermost expression. Its data would otherwise be
        // invisible, and a disagreement with it would read as agreement.
        foreach (self::viewNamesIn($expr) as $viewName) {
            self::markUnreadable($viewName);
        }

        return null;
    }

    /**
     * Literal view names of every rendering call nested in an expression the chain declined, minus
     * the calls a callback's own statement already recorded, unless the expression may write into the
     * view such a callback returns. Over-marking is the safe direction: the worst it costs is a name
     * left undeclared where a type was provable.
     *
     * @return list<string>
     */
    private static function viewNamesIn(Expr $expr): array
    {
        $names = [];
        $writes = null;

        foreach ((new NodeFinder())->find($expr, self::isViewBinder(...)) as $call) {
            if (!$call instanceof Node\Expr\CallLike || $call->isFirstClassCallable()) {
                continue;
            }

            if (isset(self::$recorded[$call]) && !($writes ??= self::mayWriteViewData($expr))) {
                continue;
            }

            // By name too: `view(data: [...], view: 'home')` puts the view name anywhere.
            $nameArg = ArgUtil::byNameOrPosition(\array_values($call->getArgs()), 0, 'view');

            // Marked under the name Laravel renders, which a spelling the chain declined
            // (`'pkg::x::y'`, `' x '`) does not carry verbatim.
            if ($nameArg instanceof Node\Arg && !$nameArg->unpack && $nameArg->value instanceof String_ && $nameArg->value->value !== '') {
                $names[] = \trim(ViewName::normalize($nameArg->value->value));
            }
        }

        return $names;
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

        return $node->name instanceof Node\Identifier
            && \in_array($node->name->toLowerString(), ['make', 'view', 'markdown'], true);
    }

    /** A producer of this view whose contribution could not be read at all. */
    public static function markUnreadable(string $viewName): void
    {
        self::$open[$viewName] = true;
    }

    /**
     * @param array<string, Union> $data     supplied variable name => the type passed for it
     * @param bool                 $complete whether the producer's key set was proven closed
     */
    public static function record(string $viewName, array $data, bool $complete): void
    {
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

            $type = self::withoutLateStatic($type);

            self::$observed[$viewName][$name] = $seen instanceof Union && $seen->getId(false) !== $type->getId(false)
                ? false
                : $type;
        }
    }

    /**
     * The type to declare for one variable, or null when nothing was proven and the caller should
     * leave it undeclared.
     */
    public static function typeFor(string $viewName, string $name): ?string
    {
        if (isset(self::$open[$viewName])) {
            return null;
        }

        $observed = self::$observed[$viewName][$name] ?? null;

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
     * Whether some provably-closed call site renders this view WITHOUT the name — which proves the
     * template can be rendered without it, so declaring it would manufacture a MissingViewVariable
     * at that call site. False while no call site has proven its key set closed.
     */
    public static function isOptional(string $viewName, string $name): bool
    {
        $supplied = self::$alwaysSupplied[$viewName] ?? null;

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

    /**
     * A template has no class scope, so `&static` (what Collection methods return) has nothing to bind
     * to. Stripped at every depth before the agreement check, so two producers differing only by it
     * agree; reached only once {@see self::dropsLateStaticSafely()} proved that only widens the type.
     * Types are copy-on-write: the instance Psalm inferred is never mutated.
     */
    private static function withoutLateStatic(Union $union): Union
    {
        $visitor = new class extends MutableTypeVisitor {
            /**
             * @template T of TypeNode
             * @param T $type
             * @param-out T $type
             * @return MutableTypeVisitor::STOP_TRAVERSAL|MutableTypeVisitor::DONT_TRAVERSE_CHILDREN|null
             * @psalm-suppress ReferenceConstraintViolation T is narrowed to the one atomic rebuilt here
             */
            #[\Override]
            protected function enterNode(TypeNode &$type): ?int
            {
                if ($type instanceof Atomic\TNamedObject) {
                    $type = $type->setIsStatic(false);
                } elseif ($type instanceof Atomic\TDependentGetClass) {
                    // `get_class($x)` keeps the type of `$x` in `as_type`, which Psalm's visitor does not descend into.
                    $inner = $type->as_type;
                    $this->traverse($inner);

                    if ($inner !== $type->as_type) {
                        $type = new Atomic\TDependentGetClass($type->typeof, $inner);
                    }
                }

                return null;
            }
        };

        $visitor->traverse($union);

        return $union;
    }
}
