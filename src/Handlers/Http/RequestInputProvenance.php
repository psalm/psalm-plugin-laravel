<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Http;

use Illuminate\Http\Request;
use PhpParser\Node;
use PhpParser\Node\ClosureUse;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Assign;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\FunctionLike;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt\Expression;
use PhpParser\NodeFinder;
use PhpParser\NodeTraverser;
use PhpParser\NodeVisitor\ParentConnectingVisitor;
use Psalm\Codebase;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Proves that an argument expression is raw, unfiltered request-input data — exact AST shapes only,
 * declining anything murky (see {@see \Psalm\LaravelPlugin\Handlers\Rules\MassAssignmentFromRequestHandler}
 * and https://github.com/psalm/psalm-plugin-laravel/issues/1574).
 *
 * Recognized shapes, each read directly as the argument OR through exactly one local variable
 * assignment in the same function-like:
 *
 * - `$request->all()` — receiver's every atomic is `Illuminate\Http\Request` or a subclass.
 * - `request()->all()` — the bare `request()` helper (no `$key` argument).
 * - `$request->query->all()` / `$request->request->all()` — Symfony's `InputBag` properties.
 * - `$request->json()->all()` — `json()` returns an `InputBag` at runtime (its stub types it
 *   `mixed`, so this is detected structurally, exactly like `request()` above).
 * - `$request->input()` / `$request->post()` / `$request->query()`, each called with NO arguments,
 *   on a receiver that is itself `Illuminate\Http\Request` or a subclass — the identical hole as
 *   `->all()`. Verified against `Illuminate\Http\Concerns\InteractsWithInput` source: `input(null)`
 *   merges the query and post/json bags, `post(null)`/`query(null)` each return their whole bag.
 *   ANY argument (a key, a default, a key list) narrows the result, so the `args === []` gate
 *   applies here exactly as it does to `->all()`.
 *
 * A PropertyFetch receiver not literally named `query`/`request` still resolves through the same
 * type-based check as any other receiver (an injected `$this->httpRequest`, or `$this->request`
 * typed as the Request object rather than Symfony's bag) — see {@see isRequestAllReceiver()}.
 *
 * `->post->all()` does not exist on `Illuminate\Http\Request` — `post()` is a method, not a
 * property. Verified against `Symfony\Component\HttpFoundation\Request` and
 * `Illuminate\Http\Concerns\InteractsWithInput` source; not implemented as a property.
 *
 * The local-assignment hop mirrors {@see ResponseFactoryTaintHandler}'s header-array proof: the
 * variable's occurrences in the enclosing function-like must number exactly two (this read and one
 * write), the write must be a direct top-level statement of the function-like's own body — never
 * nested in a branch, loop, or `try` — and must textually precede the read. The `NEVER_RESOLVED_
 * VARIABLE_NAMES` guard exists for the same reason there: superglobals and `$this` never produce a
 * competing `Variable` node, so the occurrence count could not see a disqualifying write to them.
 *
 * @internal
 */
final class RequestInputProvenance
{
    private const NEVER_RESOLVED_VARIABLE_NAMES = [
        'GLOBALS', '_GET', '_POST', '_COOKIE', '_REQUEST', '_SERVER', '_ENV', '_FILES', '_SESSION', 'this',
    ];

    /**
     * `input()`/`post()`/`query()` are checked directly against the receiver's own type — unlike
     * `all()`, they are never chained off an `InputBag` property or `json()` (there is no
     * `$request->query->query()`), so {@see isRequestAllReceiver()}'s extra shapes do not apply here.
     *
     * @var list<lowercase-string>
     */
    private const BARE_RAW_ARRAY_METHODS = ['input', 'post', 'query'];

    public static function isProven(Expr $expr, AfterExpressionAnalysisEvent $event): bool
    {
        if (self::isDirectAllExpression($expr, $event)) {
            return true;
        }

        return $expr instanceof Variable
            && \is_string($expr->name)
            && self::isLocalAssignmentToAllExpression($expr, $event);
    }

    private static function isDirectAllExpression(Expr $expr, AfterExpressionAnalysisEvent $event): bool
    {
        if (!$expr instanceof MethodCall || !$expr->name instanceof Identifier || $expr->args !== []) {
            return false;
        }

        $methodName = \strtolower($expr->name->name);

        if ($methodName === 'all') {
            return self::isRequestAllReceiver($expr->var, $event);
        }

        return \in_array($methodName, self::BARE_RAW_ARRAY_METHODS, true)
            && self::isRequestReceiverType($expr->var, $event);
    }

    private static function isRequestAllReceiver(Expr $receiver, AfterExpressionAnalysisEvent $event): bool
    {
        if ($receiver instanceof FuncCall) {
            return self::isBareRequestHelperCall($receiver);
        }

        if ($receiver instanceof PropertyFetch) {
            if ($receiver->name instanceof Identifier
                && \in_array($receiver->name->name, ['query', 'request'], true)
                && self::isRequestReceiverType($receiver->var, $event)
            ) {
                return true;
            }

            // Not (or not provably) one of the InputBag properties — the PropertyFetch itself may
            // still directly hold a Request (an injected `$this->httpRequest`, `$this->request`
            // typed as the object rather than Symfony's bag, etc). Fall through to the same
            // type-based check a plain Variable receiver already gets at the bottom of this
            // method, instead of declining outright on the property name alone (#1574 review).
            return self::isRequestReceiverType($receiver, $event);
        }

        if ($receiver instanceof MethodCall) {
            return self::isBareCall($receiver, 'json') && self::isRequestReceiverType($receiver->var, $event);
        }

        return self::isRequestReceiverType($receiver, $event);
    }

    /** @psalm-mutation-free */
    private static function isBareCall(MethodCall $call, string $methodName): bool
    {
        return $call->name instanceof Identifier
            && \strtolower($call->name->name) === $methodName
            && $call->args === [];
    }

    /**
     * The `request()` helper only resolves to `Illuminate\Http\Request` when called with no `$key`.
     *
     * @psalm-mutation-free
     */
    private static function isBareRequestHelperCall(FuncCall $call): bool
    {
        return $call->name instanceof Name
            && \strtolower($call->name->toString()) === 'request'
            && $call->args === [];
    }

    private static function isRequestReceiverType(Expr $expr, AfterExpressionAnalysisEvent $event): bool
    {
        $type = $event->getStatementsSource()->getNodeTypeProvider()->getType($expr);

        if (!$type instanceof Union) {
            return false;
        }

        $codebase = $event->getCodebase();

        foreach ($type->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TNamedObject || !self::isRequestSubclass($atomic->value, $codebase)) {
                return false;
            }
        }

        return true;
    }

    /** @psalm-external-mutation-free */
    private static function isRequestSubclass(string $className, Codebase $codebase): bool
    {
        if ($className === Request::class) {
            return true;
        }

        if (!$codebase->classExists($className)) {
            return false;
        }

        try {
            return $codebase->classExtends($className, Request::class);
        } catch (\InvalidArgumentException|UnpopulatedClasslikeException) {
            return false;
        }
    }

    /**
     * Resolves `$variable` to a same-shaped `all()` expression assigned to it earlier in the same
     * function-like, when that assignment is proven to dominate the read. See the class docblock.
     */
    private static function isLocalAssignmentToAllExpression(Variable $variable, AfterExpressionAnalysisEvent $event): bool
    {
        /** @var string $name */
        $name = $variable->name;

        if (\in_array($name, self::NEVER_RESOLVED_VARIABLE_NAMES, true)) {
            return false;
        }

        try {
            $stmts = $event->getCodebase()->getStatementsForFile($event->getStatementsSource()->getFilePath());
        } catch (\Throwable) {
            return false;
        }

        // Idempotent: safe to run once per candidate, even though the file's statements are shared
        // across every call site the handler visits in it.
        (new NodeTraverser(new ParentConnectingVisitor()))->traverse($stmts);

        /** @var Node|null $scope */
        $scope = $variable->getAttribute('parent');

        while ($scope !== null && !$scope instanceof FunctionLike) {
            /** @var Node|null $scope */
            $scope = $scope->getAttribute('parent');
        }

        if (!$scope instanceof FunctionLike) {
            return false;
        }

        /** @var list<Variable|ClosureUse> $occurrences */
        $occurrences = (new NodeFinder())->find($scope, static fn(Node $node): bool => ($node instanceof Variable && \is_string($node->name) && $node->name === $name)
            || ($node instanceof ClosureUse && \is_string($node->var->name) && $node->var->name === $name));

        if (\count($occurrences) !== 2 || self::hasDynamicVariableAccess($scope)) {
            return false;
        }

        foreach ($occurrences as $occurrence) {
            if ($occurrence === $variable) {
                continue;
            }

            $assign = $occurrence instanceof Variable ? $occurrence->getAttribute('parent') : null;
            $statement = $assign instanceof Assign ? $assign->getAttribute('parent') : null;

            if (!$occurrence instanceof Variable
                || !$assign instanceof Assign
                || $assign->var !== $occurrence
                || !$statement instanceof Expression
                || $statement->getAttribute('parent') !== $scope
                || $statement->getEndFilePos() >= $variable->getStartFilePos()
            ) {
                return false;
            }

            return self::isDirectAllExpression($assign->expr, $event);
        }

        return false;
    }

    /**
     * Mirrors {@see ResponseFactoryTaintHandler::hasDynamicVariableAccess()}: `$$x` / `${$x}` (a
     * `Variable` node whose `name` is itself an `Expr`, not a string), `extract()`, `compact()`, and
     * `get_defined_vars()` read or write a variable without ever producing a `Variable` node with
     * the target name, so the occurrence count above cannot see them. Their mere presence anywhere
     * in the scope disqualifies every variable in it.
     */
    private static function hasDynamicVariableAccess(FunctionLike $scope): bool
    {
        return (new NodeFinder())->findFirst($scope, static function (Node $node): bool {
            if ($node instanceof Variable) {
                return !\is_string($node->name);
            }

            if (!$node instanceof FuncCall || !$node->name instanceof Name) {
                return false;
            }

            return \in_array(\strtolower($node->name->toString()), ['extract', 'compact', 'get_defined_vars'], true);
        }) instanceof \PhpParser\Node;
    }
}
