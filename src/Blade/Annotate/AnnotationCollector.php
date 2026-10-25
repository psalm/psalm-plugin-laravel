<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade\Annotate;

use Illuminate\View\ViewName;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitorAbstract;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\Internal\Analyzer\ProjectAnalyzer;
use Psalm\LaravelPlugin\Blade\PreludeBuilder;
use Psalm\LaravelPlugin\Blade\ShadowEntry;
use Psalm\LaravelPlugin\Blade\ShadowRegistry;
use Psalm\LaravelPlugin\Handlers\Views\ViewCallChain;
use Psalm\LaravelPlugin\Handlers\Views\ViewClassProperties;
use Psalm\LaravelPlugin\Handlers\Views\ViewContractHandler;
use Psalm\LaravelPlugin\Internal\Arg as ArgUtil;
use Psalm\LaravelPlugin\Internal\LateStatic;
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

    /**
     * Views at least one producer left an open data set for, or could not be read at all, each with
     * the `path:line` of the first such producer ('' when unknown), so the report can point at it.
     *
     * @var array<string, string>
     */
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
     * Keys an `@includeIsolated` passes as a bare variable of the including template that Psalm saw
     * as `mixed`: the including template has not declared it yet. Keyed by view name then key, then
     * `templatePath\0variable`, each with the including template and its variable. Resolved from the
     * including template's plan instead of being observed as `mixed`, see {@see self::observations()}.
     *
     * @var array<string, array<string, array<string, array{0: string, 1: string}>>>
     */
    private static array $edges = [];

    /**
     * The `view\0key` pairs {@see self::observations()} is resolving right now, so an include cycle
     * answers `mixed` at the point it closes instead of recursing.
     *
     * @var array<string, true>
     */
    private static array $resolving = [];

    /**
     * Per analysed file, what {@see self::scan()} found: whether it may write variables by name, and the
     * variable names it uses in any way but forwarding them to an include.
     *
     * @var array<string, array{0: bool, 1: array<string, true>}>
     */
    private static array $scans = [];

    /**
     * What {@see self::observations()} answered per pair, so a diamond of includes resolves each pair
     * once instead of once per path. Emptied whenever a producer is recorded.
     *
     * Keeping an answer the cycle guard took part in is safe: every pair on a cycle, and every pair
     * fed by one, ends up with a `mixed` observation whichever member the walk started from.
     *
     * @var array<string, array<string, Union>|false|null>
     */
    private static array $resolved = [];

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
        self::$edges = [];
        self::$resolving = [];
        self::$scans = [];
        self::$resolved = [];
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
            self::record($chain->viewName, self::renderedData($chain, $event->getStatementsSource()), $chain->complete, self::site($expr, $event->getStatementsSource()));
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
            self::markUnreadable($viewName, self::site($expr, $event->getStatementsSource()));
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

        if ($include instanceof Expr\MethodCall && $chain instanceof ViewCallChain && $echoed instanceof Expr) {
            self::record(
                $chain->viewName,
                self::renderedData($chain, $source),
                $chain->complete,
                self::site($stmt, $source),
                self::deferredKeys($include, $chain, $source),
            );
            $skip = self::spine($echoed);
        }

        // Matching `make`/`view`/`markdown` on any receiver here only costs coverage (a view marked
        // open), never a wrong declaration.
        foreach ($stmt->exprs as $expr) {
            foreach (self::viewNamesIn($expr, $skip) as $viewName) {
                self::markUnreadable($viewName, self::site($stmt, $source));
            }
        }
    }

    /**
     * The types the template receives: a top-level `Renderable` is rendered to a string before the
     * template runs, so that is what the variable is declared as.
     *
     * @return array<string, Union>
     */
    private static function renderedData(ViewCallChain $chain, StatementsSource $source): array
    {
        $codebase = null;
        $rendered = [];

        foreach ($chain->supplied() as $name => $type) {
            // Only an object can be a Renderable: scalar data never needs the Codebase.
            $rendered[$name] = $type->hasNamedObjectType()
                ? ViewClassProperties::renderablesAsString($type, $codebase ??= $source->getCodebase())
                : $type;
        }

        return $rendered;
    }

    /**
     * The keys of an `@includeIsolated` whose value is a bare variable of the including template that
     * Psalm saw as exactly `mixed`, with where to ask for its real type once that template is planned.
     *
     * The including template may do nothing with the variable but forward it ({@see self::isOnlyForwarded()}):
     * any other use may change what Psalm knows about it once it is typed, which it is not yet. Locals and
     * unknown reads are declined later, by the plan ({@see AnnotationWriter::fileVariable()}). Any other
     * expression (`$m->team`) is never followed.
     *
     * @return array<string, array{0: string, 1: string}> key => including template path, variable name
     */
    private static function deferredKeys(Expr\MethodCall $include, ViewCallChain $chain, StatementsSource $source): array
    {
        $entry = ShadowRegistry::entryFor($source->getFilePath());

        // A spread or computed key may overwrite a literal entry, which ownEntries() keeps anyway.
        if (!$entry instanceof ShadowEntry || (new NodeFinder())->findFirst($include->getArgs(), static fn(Node $node): bool => $node instanceof Node\ArrayItem
            && ($node->unpack || ($node->key instanceof Expr && !$node->key instanceof String_))) instanceof Node) {
            return [];
        }

        $keys = [];

        foreach (ViewCallChain::ownEntries($include, $chain->binder) as [$key, $value]) {
            if ($value instanceof Expr\Variable
                && \is_string($value->name)
                && ($chain->data[$key] ?? null)?->getId(false) === 'mixed'
                && self::isOnlyForwarded($source, $value->name)
            ) {
                $keys[$key] = [$entry->templatePath, $value->name];
            }
        }

        return $keys;
    }

    /**
     * Whether the including template uses the variable ONLY as the value of an item in the data array of
     * an include at its top level (`$__env->make('child', ['post' => $post])`). Any other occurrence of
     * the name anywhere in the file declines every include of it: no use of a variable can be shown unable
     * to change what Psalm knows of it once it is typed (a condition, a loop between the test and the
     * include, a receiver, an argument, `isset`, an assignment either way, a reference), while a forwarded
     * one is passed on untouched. An occurrence inside a function, closure or class the template declares
     * is that scope's own local.
     *
     * A template that may write variables by name declines for every variable, as such a write has no
     * variable node to find: a call through `extract`, `call_user_func` or `call_user_func_array`, one
     * whose callee is not a plain name, any `use function` import (which can alias them), `include`,
     * `require` and `eval`, and more than one namespace block.
     */
    private static function isOnlyForwarded(StatementsSource $source, string $variable): bool
    {
        [$writesByName, $used] = self::scan($source);

        return !$writesByName && !isset($used[$variable]);
    }

    /**
     * One pass over a file, memoized: whether it may write variables by name, and the variable names it uses
     * in any way but forwarding them.
     *
     * @return array{0: bool, 1: array<string, true>}
     */
    private static function scan(StatementsSource $source): array
    {
        $path = $source->getFilePath();

        if (isset(self::$scans[$path])) {
            return self::$scans[$path];
        }

        $statements = $source->getCodebase()->getStatementsForFile($path);
        $visitor = new class extends NodeVisitorAbstract {
            public bool $writesByName = false;

            /** @var array<string, true> */
            public array $used = [];

            /** @var list<Node> the ancestors of the node being entered, outermost first */
            private array $parents = [];

            #[\Override]
            public function enterNode(Node $node): null
            {
                if ($node instanceof Expr\Variable) {
                    if (\is_string($node->name) && !AnnotationCollector::isForwarded($node, $this->parents)) {
                        $this->used[$node->name] = true;
                    }
                } elseif (AnnotationCollector::mayWriteByName($node)) {
                    $this->writesByName = true;
                }

                $this->parents[] = $node;

                return null;
            }

            /** @psalm-capabilities read-props|write-this-props|write-refs */
            #[\Override]
            public function leaveNode(Node $node): null
            {
                \array_pop($this->parents);

                return null;
            }
        };

        (new NodeTraverser($visitor))->traverse($statements);

        // More than one namespace block: each call would need its own imports to be judged.
        $writesByName = $visitor->writesByName
            || \count(\array_filter($statements, static fn(Stmt $statement): bool => $statement instanceof Stmt\Namespace_)) > 1;

        return self::$scans[$path] = [$writesByName, $visitor->used];
    }

    /**
     * Whether the variable is the value of a by-value item of an array that is an argument of the `Factory`
     * call a compiled `@includeIsolated`, `@include` and the rest of that family make with their data, and
     * no function, method, closure or class the template declares encloses it.
     *
     * @param list<Node> $parents the ancestors of `$node`, outermost first
     *
     * @internal for the scan's visitor
     */
    public static function isForwarded(Node $node, array $parents): bool
    {
        foreach ($parents as $ancestor) {
            if ($ancestor instanceof Node\FunctionLike || $ancestor instanceof Stmt\ClassLike) {
                return false;
            }
        }

        $tail = \array_slice($parents, -4);
        $call = $tail[0] ?? null;
        $arg = $tail[1] ?? null;
        $array = $tail[2] ?? null;
        $item = $tail[3] ?? null;

        return $item instanceof Node\ArrayItem && !$item->byRef && !$item->unpack && $item->value === $node
            && $array instanceof Expr\Array_ && $arg instanceof Node\Arg
            && $call instanceof Expr\MethodCall && $call->name instanceof Node\Identifier
            && \in_array($call->name->toLowerString(), ['make', 'renderwhen', 'renderunless', 'first'], true);
    }

    /**
     * Whether the node may write variables of the including scope by name, see {@see self::isOnlyForwarded()}.
     *
     * @internal for the scan's visitor
     */
    public static function mayWriteByName(Node $node): bool
    {
        return match (true) {
            $node instanceof Expr\Include_, $node instanceof Expr\Eval_ => true,
            $node instanceof Expr\FuncCall => !$node->name instanceof Node\Name
                || \in_array(\strtolower($node->name->getLast()), ['extract', 'call_user_func', 'call_user_func_array'], true),
            $node instanceof Stmt\Use_, $node instanceof Stmt\GroupUse => self::importsFunction($node->type, $node->uses),
            default => false,
        };
    }

    /**
     * @param array<Node\UseItem> $uses
     *
     * @psalm-capabilities read-props
     */
    private static function importsFunction(int $type, array $uses): bool
    {
        foreach ($uses as $use) {
            if ($type === Stmt\Use_::TYPE_FUNCTION || $use->type === Stmt\Use_::TYPE_FUNCTION) {
                return true;
            }
        }

        return false;
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

    /**
     * A producer of this view whose contribution could not be read at all. The smallest site is
     * kept, so the report does not depend on the order files were analysed in.
     */
    public static function markUnreadable(string $viewName, string $site = ''): void
    {
        self::$resolved = [];
        $viewName = self::canonical($viewName);
        $kept = self::$open[$viewName] ?? null;

        if ($kept === null || ($site !== '' && ($kept === '' || \strnatcmp($site, $kept) < 0))) {
            self::$open[$viewName] = $site;
        }
    }

    /** The `path:line` of the first producer that left this view open, '' when unknown, null when none did. */
    public static function unreadableSite(string $viewName): ?string
    {
        return self::$open[self::canonical($viewName)] ?? null;
    }

    /**
     * Where a producer sits, as the template line for one inside a compiled Blade shadow: the
     * shadow's own path is a cache file the user never sees.
     */
    private static function site(Node $node, StatementsSource $source): string
    {
        $path = $source->getFilePath();
        $line = $node->getStartLine();
        $entry = ShadowRegistry::entryFor($path);

        if ($entry instanceof ShadowEntry) {
            $path = $entry->templatePath;
            $line = $entry->lineMap[$line] ?? 0;
        }

        return $line > 0 ? "{$path}:{$line}" : $path;
    }

    /** `cards/inner` and `cards.inner` name one template: the view finder normalizes the slash. */
    private static function canonical(string $viewName): string
    {
        return ViewName::normalize($viewName);
    }

    /**
     * @param array<string, Union>                $data     supplied variable name => the type passed for it
     * @param bool                                $complete whether the producer's key set was proven closed
     * @param string                              $site     the producer's `path:line`, reported when it leaves the set open
     * @param array<string, array{0: string, 1: string}> $deferred keys of `$data` whose type is that of a variable of
     *        another template, not yet known: key => that template's path, the variable. Supplied, but not observed.
     */
    public static function record(string $viewName, array $data, bool $complete, string $site = '', array $deferred = []): void
    {
        self::$resolved = [];
        $viewName = self::canonical($viewName);

        if ($complete) {
            $keys = \array_fill_keys(\array_keys($data), true);

            self::$alwaysSupplied[$viewName] = isset(self::$alwaysSupplied[$viewName])
                ? \array_intersect_key(self::$alwaysSupplied[$viewName], $keys)
                : $keys;
        } else {
            self::markUnreadable($viewName, $site);
        }

        foreach ($data as $name => $type) {
            if (isset($deferred[$name])) {
                self::$edges[$viewName][$name][\implode("\0", $deferred[$name])] = $deferred[$name];

                continue;
            }

            $seen = self::$observed[$viewName][$name] ?? null;

            if ($seen === false) {
                continue;
            }

            if (!self::dropsLateStaticSafely($type)) {
                self::$observed[$viewName][$name] = false;

                continue;
            }

            self::$observed[$viewName][$name] = self::observe($seen ?? [], self::withoutUnboundedIntRange(LateStatic::strip($type)));
        }
    }

    /**
     * @param array<string, Union> $seen
     *
     * @return array<string, Union>
     *
     * @psalm-capabilities read-props|write-this-props|write-refs
     */
    private static function observe(array $seen, Union $type): array
    {
        $key = $type->getId(false);
        // Literals sharing an id (`1`, `100`) are combined, not replaced: the merge's containment
        // check must see every value a call site passed.
        $seen[$key] = isset($seen[$key]) ? Type::combineUnionTypes($seen[$key], $type) : $type;

        return $seen;
    }

    /**
     * What the producers of a view pass for a name: the types they observed, plus one per deferred
     * include edge, taken from the including template's planned type. An edge that cannot be typed
     * (the including template plans no type for it, or the edge closes a cycle) passes `mixed`, as it
     * would have without edges. False when a producer passed a type no declaration can carry, null
     * when nobody passed the name.
     *
     * @return array<string, Union>|false|null
     */
    private static function observations(string $viewName, string $name): array|false|null
    {
        $seen = self::$observed[$viewName][$name] ?? null;
        $edges = self::$edges[$viewName][$name] ?? [];

        if ($edges === [] || $seen === false) {
            return $seen;
        }

        $pair = "{$viewName}\0{$name}";

        if (\array_key_exists($pair, self::$resolved)) {
            return self::$resolved[$pair];
        }

        if (isset(self::$resolving[$pair])) {
            return self::observe([], Type::getMixed());
        }

        self::$resolving[$pair] = true;

        try {
            foreach ($edges as [$templatePath, $variable]) {
                $seen = self::observe($seen ?? [], self::includedType($templatePath, $variable) ?? Type::getMixed());
            }
        } finally {
            unset(self::$resolving[$pair]);
        }

        self::$resolved[$pair] = $seen;

        return $seen;
    }

    /**
     * The type an include hands on for a variable of its including template: what that template will
     * be declared as, with `null` added where it is declared `= null`.
     */
    private static function includedType(string $templatePath, string $variable): ?Union
    {
        $planned = AnnotationWriter::fileVariable($templatePath, $variable);

        if ($planned === null || $planned[0] === null) {
            return null;
        }

        $type = Type::parseString($planned[0]);

        return $planned[1] ? Type::combineUnionTypes($type, Type::getNull()) : $type;
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
        $distinct = self::observations($viewName, $name);

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

        $distinct = self::observations($viewName, $name);

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
     * is invariant, so each counts as a class; arrays when one call site's array covers every other
     * (`[]` and `list{string}` beside `list<string>`). Never merged: a `mixed` producer, two scalar
     * kinds, arrays only a new combined shape covers, an array or a scalar with an object. Each usually
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

        // A covering producer's own type, never the combined one: combining shapes invents optional
        // keys and value unions no call site passed.
        if ($atomics !== [] && \count(\array_filter($atomics, static fn(Atomic $atomic): bool => $atomic instanceof Atomic\TArray || $atomic instanceof Atomic\TKeyedArray)) === \count($atomics)) {
            foreach ($distinct as $candidate) {
                if (\array_filter($distinct, static fn(Union $type): bool => !self::isContained($type, $candidate)) === []) {
                    return $candidate;
                }
            }

            return null;
        }

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
