<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Util\Ast;

use PhpParser\Node;
use PhpParser\NodeVisitor;
use PhpParser\NodeVisitorAbstract;

/**
 * Walks a `Closure`'s body collecting every `return` statement's expression
 * while refusing to descend into nested closures / arrow functions / function
 * declarations / class methods (their returns belong to the inner function,
 * not the outer one we are inferring for).
 *
 * Sets `$bailed = true` and stops traversal when a `return;` with no
 * expression is encountered — the caller treats that the same as any other
 * unhandled shape and abandons inference for the closure.
 *
 * @internal Helper for {@see ClosureTypeFactory::inferReturnFromBody()}.
 */
final class BodyReturnCollectorVisitor extends NodeVisitorAbstract
{
    /** @var list<Node\Expr> */
    private array $returnExprs = [];

    private bool $bailed = false;

    /**
     * Collected `return <expr>;` expressions in source order, or an empty list
     * when the body contained no returns. Read-only after traversal; mirrors
     * the accessor convention in {@see ClosureDocblockIndexVisitor::getEntries()}
     * so the two visitors in this directory expose the same shape.
     *
     * @return list<Node\Expr>
     */
    public function getReturnExpressions(): array
    {
        return $this->returnExprs;
    }

    /**
     * `true` when the walker hit `return;` (no expression). Load-bearing
     * because it distinguishes "the closure has explicit returns AND a
     * fall-through `return null`" from "the closure has no returns at all".
     * The first case must bail (some-returns-plus-implicit-null would
     * silently miss `null` in the inferred union); the second case is the
     * same as a body the visitor never finds returns in.
     */
    public function hasBailed(): bool
    {
        return $this->bailed;
    }

    /**
     * Reset per-traversal state so the visitor can be reused safely against
     * multiple closure bodies. Mirrors the sibling
     * {@see ClosureDocblockIndexVisitor::beforeTraverse()} — without this
     * reset, a second `traverse()` call would accumulate returns from the
     * first run AND keep a stale `bailed = true` indefinitely.
     *
     * @psalm-external-mutation-free Mutation stays inside the visitor.
     */
    #[\Override]
    public function beforeTraverse(array $nodes): ?array
    {
        $this->returnExprs = [];
        $this->bailed = false;

        return null;
    }

    /**
     * @psalm-external-mutation-free The visitor mutates its own `returnExprs`
     *         / `bailed` fields; nothing outside the instance changes. Same
     *         posture as {@see ClosureDocblockIndexVisitor::enterNode()}.
     */
    #[\Override]
    public function enterNode(Node $node): ?int
    {
        if ($node instanceof Node\Expr\Closure
            || $node instanceof Node\Expr\ArrowFunction
            || $node instanceof Node\Stmt\Function_
            || $node instanceof Node\Stmt\ClassMethod
        ) {
            return NodeVisitor::DONT_TRAVERSE_CHILDREN;
        }

        if ($node instanceof Node\Stmt\Return_) {
            if (!$node->expr instanceof \PhpParser\Node\Expr) {
                $this->bailed = true;
                return NodeVisitor::STOP_TRAVERSAL;
            }

            $this->returnExprs[] = $node->expr;
        }

        return null;
    }
}
