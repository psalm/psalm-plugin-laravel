<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Pest;

use PhpParser\Node\Arg;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\NodeFinder;
use Psalm\Issue\InternalMethod;
use Psalm\Issue\InvalidScope;
use Psalm\Plugin\EventHandler\BeforeAddIssueInterface;
use Psalm\Plugin\EventHandler\BeforeFileAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\BeforeAddIssueEvent;
use Psalm\Plugin\EventHandler\Event\BeforeFileAnalysisEvent;

/**
 * Pest framework support for Laravel projects.
 *
 * Pest is a test framework whose DSL deliberately diverges from class-based test conventions:
 * test files are plain PHP scripts that call top-level `test()` / `it()` / `describe()` etc.,
 * passing closures that Pest later binds to a generated TestCase subclass via Closure::bind().
 * The DSL chain (`expect(...)->toBe(...)`, `test(...)->with(...)`, `uses(...)->in(...)`) is
 * implemented on classes that Pest marks `@internal` even though they ARE the documented
 * public surface user code is expected to call.
 *
 * Two Psalm checks misfire on this pattern:
 *
 *  1. InvalidScope on `$this` inside Pest closures that Pest binds to the TestCase at
 *     runtime (`test`, `it`, `beforeEach`, `afterEach`). Suppressed only when the offending
 *     `$this` reference is inside a binding closure's file range — `beforeAll`/`afterAll`
 *     run in static context (no `$this`), `describe`'s own closure is unbound, so `$this`
 *     there stays flagged.
 *
 *  2. InternalMethod on the Pest DSL surface (`Pest\PendingCalls\*`, `Pest\Mixins\Expectation`,
 *     `Pest\Expectations\*` higher-order helpers, `Pest\Configuration` returned by `pest()`).
 *     Suppressed file-wide inside Pest test files because these DSL chains pepper the whole
 *     file and unrelated `@internal` namespaces are excluded by the prefix list.
 *
 * Detection happens once per file in `beforeAnalyzeFile()` via AST inspection of the parsed
 * statements (Psalm already parsed the file at this point; we read its AST). Results are
 * stored in two per-process static maps: one boolean "is Pest file" status, one list of
 * binding-closure byte ranges. The hot `beforeAddIssue()` path is then an O(1) lookup
 * plus, for InvalidScope only, a linear scan over the binding ranges of the issue's file.
 *
 * Out of scope (potential follow-ups):
 *  - Resolving `$this` inside Pest closures to the bound `TestCase` type, which would
 *    additionally fix downstream MixedMethodCall on `$this->...` calls.
 *  - Higher-order expectation property access (`expect(x)->not->toBeFalse()`), where Pest
 *    resolves `->not` via `__get` and Psalm reports UndefinedPropertyFetch.
 *
 * @internal
 */
final class PestSupportHandler implements BeforeFileAnalysisInterface, BeforeAddIssueInterface
{
    /**
     * Lowercased prefixes that identify Pest's `@internal` DSL surface as method ids.
     * `MethodIssue::$method_id` is lowercased on construction (see vendor MethodIssue.php),
     * so these must be lowercased too. PestSupportHandlerTest exercises each prefix to
     * guard against the casing invariant silently breaking.
     */
    private const PEST_INTERNAL_METHOD_PREFIXES = [
        // PendingCalls: TestCall, UsesCall, BeforeEachCall, AfterEachCall, DescribeCall.
        'pest\\pendingcalls\\',
        // Single class — back of `expect(...)->toX()` calls.
        'pest\\mixins\\expectation::',
        // Higher-order helpers: HigherOrderExpectation, EachExpectation, OppositeExpectation.
        // All three are `@internal` in Pest source.
        'pest\\expectations\\',
        // Returned by `pest()` for the tests/Pest.php config-style DSL
        // (e.g. `pest()->extend(TestCase::class)`).
        'pest\\configuration::',
    ];

    /**
     * Lowercased Pest top-level function names. A file containing ANY of these as a
     * top-level call (including inside a namespace declaration) is treated as a Pest
     * test file. Sourced from `Pest\Functions` (vendor/pestphp/pest/src/Functions.php).
     */
    private const PEST_DSL_FUNCTIONS = [
        'test' => true,
        'it' => true,
        'describe' => true,
        'uses' => true,
        'beforeeach' => true,
        'aftereach' => true,
        'beforeall' => true,
        'afterall' => true,
        'todo' => true,
        'dataset' => true,
        'pest' => true,
        'expect' => true,
        'covers' => true,
        'mutates' => true,
    ];

    /**
     * Pest DSL functions whose closure argument is bound to the TestCase at runtime, so
     * `$this` inside the closure body is valid. Value is the 0-based index of the closure
     * argument in the function signature.
     *
     * Deliberately excludes:
     *  - `beforeAll` / `afterAll`: run in static context per Pest source
     *    (`TestSuite::getInstance()->beforeAll->set($closure)`), no `$this`.
     *  - `describe`: groups inner test calls; the describe closure itself has no `$this`.
     *    Inner `test()` / `it()` calls inside are detected separately.
     *  - `dataset`: closure returns data, not bound to a test context.
     */
    private const PEST_BINDING_DSL_FUNCTIONS = [
        'test' => 1,
        'it' => 1,
        'beforeeach' => 0,
        'aftereach' => 0,
    ];

    /**
     * Per-file detection status, keyed by the file path Psalm reports on the
     * CodeLocation / StatementsSource. `true` = detected Pest file; `false` = inspected
     * and not Pest (cached so long-lived processes don't re-walk the AST). Each Psalm
     * worker process (pcntl_fork) keeps its own copy.
     *
     * @var array<string, bool>
     */
    private static array $pestFileStatus = [];

    /**
     * Per-file byte ranges of binding closures. An `$this` reference whose CodeLocation's
     * raw_file_start falls inside any recorded range is treated as TestCase-bound and the
     * InvalidScope check is suppressed for it.
     *
     * @var array<string, list<array{int, int}>>
     */
    private static array $pestBindingRanges = [];

    /**
     * Walk each file's AST once. Mark the file as Pest if it contains a top-level Pest
     * DSL call (or a Pest DSL call inside a top-level `namespace { ... }` block for the
     * Pest-3 style). Collect the byte ranges of every binding closure so InvalidScope
     * can be suppressed precisely.
     */
    #[\Override]
    public static function beforeAnalyzeFile(BeforeFileAnalysisEvent $event): void
    {
        $filePath = $event->getStatementsSource()->getFilePath();

        if (isset(self::$pestFileStatus[$filePath])) {
            return;
        }

        $stmts = $event->getStmts();

        if (! self::containsTopLevelPestCall($stmts)) {
            self::$pestFileStatus[$filePath] = false;

            return;
        }

        self::$pestFileStatus[$filePath] = true;
        self::$pestBindingRanges[$filePath] = self::collectBindingClosureRanges($stmts);
    }

    /**
     * @psalm-external-mutation-free
     */
    #[\Override]
    public static function beforeAddIssue(BeforeAddIssueEvent $event): ?bool
    {
        $issue = $event->getIssue();

        // BeforeAddIssue fires for every issue Psalm emits. The instanceof gate keeps
        // the handler off the hot path for the ~99% of issues we never act on.
        if (! $issue instanceof InvalidScope && ! $issue instanceof InternalMethod) {
            return null;
        }

        $filePath = $issue->code_location->file_path;
        if (! (self::$pestFileStatus[$filePath] ?? false)) {
            return null;
        }

        if ($issue instanceof InvalidScope) {
            return self::isInsideBindingClosure($filePath, $issue->code_location->raw_file_start)
                ? false
                : null;
        }

        // InternalMethod: only suppress Pest's own DSL. A user calling some unrelated
        // `@internal` method from a Pest test file should still be flagged. The cheap
        // `pest\\` short-circuit avoids walking the prefix list for non-Pest namespaces.
        if (! \str_starts_with($issue->method_id, 'pest\\')) {
            return null;
        }

        foreach (self::PEST_INTERNAL_METHOD_PREFIXES as $prefix) {
            if (\str_starts_with($issue->method_id, $prefix)) {
                return false;
            }
        }

        return null;
    }

    /**
     * @param array<Stmt> $stmts
     */
    private static function containsTopLevelPestCall(array $stmts): bool
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Stmt\Namespace_) {
                if (self::containsTopLevelPestCall($stmt->stmts)) {
                    return true;
                }
                continue;
            }

            if ($stmt instanceof Stmt\Expression && self::isPestDslExpression($stmt->expr)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Walk a top-level expression to find a Pest DSL func call at the root.
     *
     * The Pest DSL is chainable: `uses(...)->in(...)`, `test('a', fn () => null)->with([...])`,
     * `pest()->extend(...)`. We unwrap the method-call chain back to its leftmost receiver,
     * which (for a Pest statement) is a `FuncCall` to one of the Pest DSL functions.
     */
    private static function isPestDslExpression(Expr $expr): bool
    {
        while ($expr instanceof MethodCall || $expr instanceof NullsafeMethodCall) {
            $expr = $expr->var;
        }

        if (! $expr instanceof FuncCall || ! $expr->name instanceof Name) {
            return false;
        }

        return isset(self::PEST_DSL_FUNCTIONS[$expr->name->toLowerString()]);
    }

    /**
     * Collect [start, end] byte ranges of every closure argument passed to a
     * binding Pest DSL function anywhere in the AST. Walks nested expressions too,
     * since `describe(..., function () { test(..., fn () => $this->x); })` puts the
     * inner `test()` closure deep inside the describe callback.
     *
     * @param array<Stmt> $stmts
     * @return list<array{int, int}>
     */
    private static function collectBindingClosureRanges(array $stmts): array
    {
        $ranges = [];

        /** @var list<FuncCall> $funcCalls */
        $funcCalls = (new NodeFinder())->findInstanceOf($stmts, FuncCall::class);

        foreach ($funcCalls as $call) {
            if (! $call->name instanceof Name) {
                continue;
            }

            $argIndex = self::PEST_BINDING_DSL_FUNCTIONS[$call->name->toLowerString()] ?? null;
            if ($argIndex === null) {
                continue;
            }

            $arg = $call->args[$argIndex] ?? null;
            if (! $arg instanceof Arg) {
                continue;
            }

            $closure = $arg->value;
            if (! $closure instanceof Closure && ! $closure instanceof ArrowFunction) {
                continue;
            }

            $start = $closure->getStartFilePos();
            $end = $closure->getEndFilePos();
            if ($start < 0 || $end < 0) {
                // Synthetic/un-positioned nodes; cannot range-check against.
                continue;
            }

            $ranges[] = [$start, $end];
        }

        return $ranges;
    }

    /**
     * @psalm-external-mutation-free
     */
    private static function isInsideBindingClosure(string $filePath, int $rawFileStart): bool
    {
        foreach (self::$pestBindingRanges[$filePath] ?? [] as [$start, $end]) {
            if ($rawFileStart >= $start && $rawFileStart <= $end) {
                return true;
            }
        }

        return false;
    }
}
