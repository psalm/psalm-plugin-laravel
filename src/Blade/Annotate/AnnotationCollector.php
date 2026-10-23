<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade\Annotate;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use Psalm\LaravelPlugin\Blade\PreludeBuilder;
use Psalm\LaravelPlugin\Handlers\Views\ViewCallChain;
use Psalm\LaravelPlugin\Handlers\Views\ViewContractHandler;
use Psalm\LaravelPlugin\Internal\Arg as ArgUtil;
use Psalm\Plugin\EventHandler\AfterStatementAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterStatementAnalysisEvent;
use Psalm\StatementsSource;
use Psalm\Type;
use Psalm\Type\Atomic;
use Psalm\Type\MutableTypeVisitor;
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
 * view agreed on it AND no producer left the view's data set open — an open set can carry the same
 * key with another type, invisibly.
 *
 * @internal
 */
final class AnnotationCollector implements AfterStatementAnalysisInterface
{
    private static bool $enabled = false;

    /**
     * Keyed by view name then variable name. `false` marks a key two producers disagreed on.
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

        if ($chain instanceof ViewCallChain) {
            self::record($chain->viewName, $chain->supplied(), $chain->complete);

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
     * A compiled include is a producer of the view it renders like any `view()` call: left unseen, a
     * controller's type gets declared that the include then passes something else for. One that
     * passes the including scope (`@include`, `@extends`) leaves the data set open.
     */
    private static function recordEcho(Stmt\Echo_ $stmt, StatementsSource $source): void
    {
        $include = ViewContractHandler::compiledInclude($stmt);
        $chain = $include instanceof Expr\MethodCall ? ViewCallChain::fromInclude($include, $source) : null;

        if ($chain instanceof ViewCallChain) {
            self::record($chain->viewName, $chain->supplied(), $chain->complete);

            return;
        }

        foreach ($stmt->exprs as $expr) {
            foreach (self::viewNamesIn($expr) as $viewName) {
                self::markUnreadable($viewName);
            }
        }
    }

    /**
     * Literal view names of every rendering call nested in an expression the chain declined. Over-
     * marking is the safe direction: the worst it costs is a name left undeclared where a type was provable.
     *
     * @return list<string>
     */
    private static function viewNamesIn(Expr $expr): array
    {
        $names = [];

        foreach ((new NodeFinder())->find($expr, self::isViewBinder(...)) as $call) {
            if (!$call instanceof Node\Expr\CallLike || $call->isFirstClassCallable()) {
                continue;
            }

            $args = \array_values($call->getArgs());
            $method = $call instanceof Expr\FuncCall ? 'view' : self::methodName($call);

            // By name too: `view(data: [...], view: 'home')` puts the view name anywhere.
            $nameArg = match ($method) {
                'renderwhen', 'renderunless' => ArgUtil::byNameOrPosition($args, 1, 'view'),
                'first' => ArgUtil::byNameOrPosition($args, 0, 'views'),
                default => ArgUtil::byNameOrPosition($args, 0, 'view'),
            };

            if (!$nameArg instanceof Node\Arg || $nameArg->unpack) {
                continue;
            }

            $candidates = [$nameArg->value];

            // `first()` renders whichever candidate exists, so every literal one may be the view.
            if ($nameArg->value instanceof Expr\Array_) {
                $candidates = [];

                foreach ($nameArg->value->items as $item) {
                    $candidates[] = $item?->value;
                }
            }

            foreach ($candidates as $candidate) {
                if ($candidate instanceof String_ && $candidate->value !== '') {
                    $names[] = $candidate->value;
                }
            }
        }

        return $names;
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

        // The other Factory methods compiled includes call (`@includeFirst`, `@includeWhen`, `@each`),
        // too common as names to match on any receiver.
        return $node instanceof Expr\MethodCall
            && $node->var instanceof Expr\Variable
            && $node->var->name === '__env'
            && \in_array($method, ['first', 'renderwhen', 'renderunless', 'rendereach'], true);
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
            // Normalized before comparing, so `Foo&static` and `Foo` from two call sites agree.
            $type = self::printable($type);
            $seen = self::$observed[$viewName][$name] ?? null;

            if ($seen === false) {
                continue;
            }

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
     * The type as a template can declare it, at any depth. A template has no class scope for a
     * `static` marker to refer to, and `getId(false)` still prints it on a generic object
     * (`Collection<int, string>&static`). An unbounded `int<min, max>` is plain `int`.
     */
    private static function printable(Union $union): Union
    {
        $visitor = new class extends MutableTypeVisitor {
            /**
             * @psalm-capabilities read-props|write-this-props|write-refs
             */
            #[\Override]
            protected function enterNode(TypeNode &$type): ?int
            {
                if ($type instanceof Atomic\TNamedObject && $type->is_static) {
                    $type = $type->setIsStatic(false);
                } elseif ($type instanceof Atomic\TIntRange && $type->min_bound === null && $type->max_bound === null) {
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
        $visitor = new class extends TypeVisitor {
            public bool $found = false;

            /**
             * @psalm-external-mutation-free
             */
            #[\Override]
            protected function enterNode(TypeNode $type): ?int
            {
                if ($type instanceof Atomic\TTemplateParam
                    || $type instanceof Atomic\TTemplateParamClass
                    || $type instanceof Atomic\TTemplateIndexedAccess
                    || $type instanceof Atomic\TTemplateKeyOf
                    || $type instanceof Atomic\TTemplatePropertiesOf
                    || $type instanceof Atomic\TTemplateValueOf
                    || $type instanceof Atomic\TConditional
                ) {
                    $this->found = true;

                    return self::STOP_TRAVERSAL;
                }

                return null;
            }
        };

        $visitor->traverse($union);

        return $visitor->found;
    }
}
