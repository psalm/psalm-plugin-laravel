<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Util\Ast;

use PhpParser\Comment\Doc;
use PhpParser\Node;
use PhpParser\NodeVisitorAbstract;
use Psalm\Aliases;

/**
 * Walks a parsed file once, indexing every {@see Node\Expr\Closure} /
 * {@see Node\Expr\ArrowFunction} by its start line and pairing it with both the
 * attached docblock and the namespace + `use` aliases active at that location.
 *
 * Doc attachment rule (deliberately strict): own docblock first, then the docblock
 * on the immediately wrapping `Stmt\Expression`. The wrapping-statement fallback is
 * what catches the Inertia-style call site:
 *
 *     /** @param array<array-key, mixed>  $props *\/
 *     Router::macro('inertia', function ($uri, $component, $props = []) { ... });
 *
 * Walking further up the ancestor chain would risk attaching an outer function's
 * docblock to a nested inline closure — explicitly out of scope.
 *
 * Namespace and `use` state are tracked on push/pop stacks so braced multi-namespace
 * files don't bleed aliases across sections, and pre-namespace top-level `use`
 * statements don't leak into a later `namespace { ... }` block.
 *
 * Each entry also retains the raw `Closure` / `ArrowFunction` node. PR #994 uses
 * it for body-flow return inference when neither docblock `@return` nor native
 * reflection covers the closure — without the node the factory would have to
 * re-parse the file just to walk the body, defeating the per-run cache.
 *
 * @internal Helper for {@see ClosureTypeFactory::indexFile()}.
 */
final class ClosureDocblockIndexVisitor extends NodeVisitorAbstract
{
    /** @var array<int, list<array{0: ?Doc, 1: Aliases, 2: Node\Expr\Closure|Node\Expr\ArrowFunction}>> startLine => list of (doc, aliases, node) */
    private array $entries = [];

    /** @var list<Node> stack of currently-open ancestor nodes (parent at the tail). */
    private array $nodeStack = [];

    /**
     * Stack of {namespace, uses, uses_flipped, aliases_obj} frames. A bottom
     * sentinel frame holds top-level file-scope state; entering a
     * {@see Node\Stmt\Namespace_} pushes a fresh frame, leaving it pops.
     *
     * `aliases_obj` is a lazily-built {@see Aliases} cached for the frame so
     * the N closures sharing one namespace allocate exactly one `Aliases`
     * instance between them. Invalidated to `null` whenever `recordUse()`
     * extends `uses` / `uses_flipped`, so an inner closure sees aliases that
     * appeared before it in source order.
     *
     * Annotated as `non-empty-array<int<0, max>, …>` rather than
     * `non-empty-list<…>` because mutating nested elements via index access
     * (`$stack[$tip]['uses'][$alias] = …`) makes Psalm widen the inferred
     * shape to `non-empty-array`. The semantic invariant (push/pop only at
     * the tail) still holds.
     *
     * @var non-empty-array<int<0, max>, array{namespace: ?string, uses: array<lowercase-string, string>, uses_flipped: array<lowercase-string, string>, aliases_obj: ?Aliases}>
     */
    private array $aliasStack = [['namespace' => null, 'uses' => [], 'uses_flipped' => [], 'aliases_obj' => null]];

    /**
     * Returns the index of closures keyed by start line, as built by the
     * traversal. Read-only after traversal completes; mutating writes happen
     * exclusively from `beforeTraverse` / `enterNode`. Exposed via a method
     * rather than a public field so callers cannot tamper with the storage.
     *
     * @return array<int, list<array{0: ?Doc, 1: Aliases, 2: Node\Expr\Closure|Node\Expr\ArrowFunction}>>
     */
    public function getEntries(): array
    {
        return $this->entries;
    }

    /**
     * @psalm-external-mutation-free Visitor state mutation is the entire point of
     *         a traversal callback; the parent's stub doesn't carry purity tags
     *         but Psalm's own pure-method analysis flags any mutating override
     *         without one. None of the mutation escapes the visitor instance.
     */
    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        $this->entries = [];
        $this->nodeStack = [];
        $this->aliasStack = [['namespace' => null, 'uses' => [], 'uses_flipped' => [], 'aliases_obj' => null]];

        return null;
    }

    #[\Override]
    public function enterNode(Node $node): ?Node
    {
        if ($node instanceof Node\Stmt\Namespace_) {
            $this->aliasStack[] = [
                'namespace' => $node->name?->toString(),
                'uses' => [],
                'uses_flipped' => [],
                'aliases_obj' => null,
            ];
        } elseif ($node instanceof Node\Stmt\Use_ && $node->type === Node\Stmt\Use_::TYPE_NORMAL) {
            foreach ($node->uses as $use) {
                $this->recordUse($use->name->toString(), $use->getAlias()->toString());
            }
        } elseif ($node instanceof Node\Stmt\GroupUse && $node->type === Node\Stmt\Use_::TYPE_NORMAL) {
            $prefix = $node->prefix->toString();
            foreach ($node->uses as $use) {
                // Mixed groups allow per-entry `function` / `const` typing; only
                // normal or unspecified entries contribute class aliases.
                if ($use->type !== Node\Stmt\Use_::TYPE_UNKNOWN && $use->type !== Node\Stmt\Use_::TYPE_NORMAL) {
                    continue;
                }

                $this->recordUse($prefix . '\\' . $use->name->toString(), $use->getAlias()->toString());
            }
        }

        if ($node instanceof Node\Expr\Closure || $node instanceof Node\Expr\ArrowFunction) {
            $doc = $node->getDocComment();
            if (!$doc instanceof Doc) {
                // Inertia-style: the closure's nearest *enclosing statement* is a
                // `Stmt\Expression` wrapping the `Router::macro('inertia', fn(){...})`
                // call, and the docblock is attached to that statement. The
                // closure's direct parent in the AST is usually an `Arg` /
                // `StaticCall` / `MethodCall` chain — not a Stmt — so we walk
                // upward through expression nodes until we hit the first Stmt.
                //
                // Deliberately strict: if the enclosing statement is anything
                // other than `Stmt\Expression` (e.g. `Stmt\Return_`,
                // `Stmt\Function_`, `Stmt\If_`), we ignore its docblock —
                // attaching an outer function's `@return Foo` to a nested closure
                // would be wrong.
                $doc = $this->findWrappingExpressionDoc();
            }

            $this->entries[$node->getStartLine()][] = [$doc, $this->aliasesForCurrentFrame(), $node];
        }

        $this->nodeStack[] = $node;

        return null;
    }

    /**
     * @psalm-external-mutation-free
     */
    #[\Override]
    public function leaveNode(Node $node): ?Node
    {
        \array_pop($this->nodeStack);
        if ($node instanceof Node\Stmt\Namespace_ && \count($this->aliasStack) > 1) {
            \array_pop($this->aliasStack);
        }

        return null;
    }

    /**
     * Walk upward from the current closure node through any chain of expression
     * nodes (`Arg`, `MethodCall`, `StaticCall`, `Array_`, etc.) until reaching
     * the first enclosing statement. Return its docblock only when that statement
     * is a `Stmt\Expression` — i.e. the closure is the result expression of a
     * statement like `Foo::macro('name', fn () => ...);`.
     *
     * Any other enclosing-statement kind (return, throw, function body opener,
     * control-flow construct) yields `null` — its docblock semantically belongs
     * to the statement, not to a closure embedded inside it.
     */
    private function findWrappingExpressionDoc(): ?Doc
    {
        for ($i = \count($this->nodeStack) - 1; $i >= 0; --$i) {
            $candidate = $this->nodeStack[$i];
            if (!$candidate instanceof Node\Stmt) {
                continue;
            }

            if ($candidate instanceof Node\Stmt\Expression) {
                return $candidate->getDocComment();
            }

            return null;
        }

        return null;
    }

    /**
     * Build (or return) the `Aliases` for the current namespace frame. Cached
     * on the frame itself so a file with N closures in one namespace allocates
     * exactly one `Aliases` instance for them — important for large vendor
     * files where N can reach the thousands.
     *
     * Cache is invalidated by {@see self::recordUse()} whenever the frame's
     * `uses` / `uses_flipped` arrays grow, so a closure declared after a `use`
     * statement still sees the freshly-added alias.
     */
    /**
     * @psalm-external-mutation-free Caches the result on the frame; mutation
     *         stays inside the visitor instance.
     */
    private function aliasesForCurrentFrame(): Aliases
    {
        $tipIndex = \count($this->aliasStack) - 1;
        $frame = $this->aliasStack[$tipIndex];
        if ($frame['aliases_obj'] instanceof Aliases) {
            return $frame['aliases_obj'];
        }

        $aliases = new Aliases($frame['namespace'], $frame['uses'], [], [], $frame['uses_flipped'], [], []);

        $this->aliasStack[$tipIndex]['aliases_obj'] = $aliases;

        return $aliases;
    }

    /**
     * @psalm-external-mutation-free Mutation stays inside the visitor.
     */
    private function recordUse(string $fqcn, string $alias): void
    {
        $tipIndex = \count($this->aliasStack) - 1;
        $aliasLower = \strtolower($alias);
        $this->aliasStack[$tipIndex]['uses'][$aliasLower] = $fqcn;
        $this->aliasStack[$tipIndex]['uses_flipped'][\strtolower($fqcn)] = $alias;
        // Drop the cached Aliases so the next closure rebuilds it with the
        // newly-recorded use entry. Cheap — closures are rarer than `use`
        // statements at the top of a file.
        $this->aliasStack[$tipIndex]['aliases_obj'] = null;
    }
}
