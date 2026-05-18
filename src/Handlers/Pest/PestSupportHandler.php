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
use Psalm\Codebase;
use Psalm\Issue\InternalMethod;
use Psalm\Issue\InvalidScope;
use Psalm\Plugin\EventHandler\BeforeAddIssueInterface;
use Psalm\Plugin\EventHandler\BeforeFileAnalysisInterface;
use Psalm\Plugin\EventHandler\BeforeStatementAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\BeforeAddIssueEvent;
use Psalm\Plugin\EventHandler\Event\BeforeFileAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\BeforeStatementAnalysisEvent;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Pest framework support for Laravel projects.
 *
 * Pest test files are plain PHP scripts that call top-level `test()` / `it()` / `describe()`
 * etc., passing closures that Pest later binds to a generated TestCase subclass via
 * Closure::bind(). The DSL chain (`expect(...)->toBe(...)`, `test(...)->with(...)`,
 * `uses(...)->in(...)`) is implemented on classes that Pest marks `@internal` even though
 * they ARE the documented public surface user code is expected to call.
 *
 * The handler addresses two Psalm misfires inside detected Pest files:
 *
 *  1. **`$this` in binding closures**: Pest binds the closure passed to `test`, `it`,
 *     `beforeEach`, `afterEach` to a TestCase subclass at runtime. Before Psalm analyzes
 *     a statement inside such a closure, we inject `$this` into the statement context with
 *     the resolved TestCase type. This makes `$this->...` calls type-check naturally and
 *     prevents the InvalidScope check from firing in the first place — no suppression
 *     needed. Crucially this also avoids a cascade where suppressing InvalidScope alone
 *     would leave `$this` as `mixed` and trip MixedMethodCall/MixedPropertyFetch on every
 *     subsequent access (observed on real-world Laravel apps: ~2-3× the suppressed count
 *     reappear as Mixed errors).
 *
 *     `beforeAll` / `afterAll` are NOT bound (Pest runs them in static context per
 *     `TestSuite::getInstance()->beforeAll->set($closure)`). `describe`'s own closure is
 *     unbound — only the inner `test()`/`it()` calls inside it bind. `$this` in those
 *     positions stays correctly flagged.
 *
 *  2. **`InternalMethod` on the Pest DSL surface**: dropped only when the called method
 *     id starts with one of `Pest\PendingCalls\`, `Pest\Mixins\Expectation::`,
 *     `Pest\Expectations\`, or `Pest\Configuration::`. Unrelated `@internal` calls from
 *     Pest test files remain flagged.
 *
 * **TestCase resolution.** First time a Pest file is seen, the handler probes the user's
 * codebase for the bound TestCase class. `Tests\TestCase` (the Laravel default) is tried
 * first, then `PHPUnit\Framework\TestCase`. The result is cached for the rest of the
 * process. Per-directory `uses(X::class)->in(...)` mappings from `tests/Pest.php` are not
 * yet honored — a follow-up could parse them for projects with multiple test base classes.
 *
 * **Detection.** `beforeAnalyzeFile()` walks the parsed AST once per file. A file is
 * marked Pest if it contains a top-level call to any Pest DSL function (including inside
 * a top-level `namespace { ... }` block). For each binding-DSL call, the closure
 * argument's byte range is recorded along with the resolved TestCase FQCN.
 *
 * **Hot path.** `beforeAddIssue` is a single class-instanceof gate plus an O(1) `isset()`
 * lookup. `beforeStatementAnalysis` is gated on the file map first; non-Pest files exit
 * in one isset check.
 *
 * Out of scope:
 *  - Higher-order expectation property access (`expect(x)->not->toBeFalse()`), where Pest
 *    resolves `->not` via `__get` and Psalm reports UndefinedPropertyFetch.
 *  - Per-directory `uses()->in()` parsing.
 *
 * @internal
 */
final class PestSupportHandler implements
    BeforeFileAnalysisInterface,
    BeforeStatementAnalysisInterface,
    BeforeAddIssueInterface
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
        // Returned by `pest()` for the tests/Pest.php config-style DSL.
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
     * TestCase class candidates probed at first Pest detection. Order matters: the
     * Laravel default `Tests\TestCase` is checked first because Laravel scaffolds it
     * and any Pest test in a Laravel app inherits from it. `PHPUnit\Framework\TestCase`
     * is the fallback for non-Laravel Pest projects or sandboxes without Tests\TestCase.
     */
    private const TEST_CASE_CANDIDATES = [
        'Tests\\TestCase',
        'PHPUnit\\Framework\\TestCase',
    ];

    /**
     * Per-file detection status. `true` = Pest file; `false` = inspected and not Pest
     * (negative result cached so long-lived processes don't re-walk the AST). Each
     * Psalm worker process (pcntl_fork) keeps its own copy.
     *
     * @var array<string, bool>
     */
    private static array $pestFileStatus = [];

    /**
     * Per-file list of `[range_start, range_end, test_case_fqcn]` for every binding
     * closure. A statement whose start position falls inside any range has its context
     * `$this` populated with `TNamedObject($fqcn)` before analysis.
     *
     * @var array<string, list<array{int, int, string}>>
     */
    private static array $pestBindingScopes = [];

    /**
     * Resolved TestCase FQCN for this process. Computed once on the first Pest file
     * and reused. Null means not yet resolved.
     */
    private static ?string $resolvedTestCaseClass = null;

    /**
     * Walk each file's AST once. Mark the file as Pest if it contains a top-level Pest
     * DSL call (or a Pest DSL call inside a top-level `namespace { ... }` block).
     * Collect the byte ranges of every binding closure so InvalidScope can be suppressed
     * precisely.
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

        $testCaseClass = self::resolveTestCaseClass($event->getCodebase());
        self::$pestBindingScopes[$filePath] = self::collectBindingClosureScopes($stmts, $testCaseClass);
    }

    /**
     * Inject `$this` into the analysis context for statements that sit inside a Pest
     * binding closure. Mutation only happens when `$this` is not already present in
     * scope, so we never override Psalm's own analysis (e.g. Closure::bind chains it
     * already understands).
     */
    #[\Override]
    public static function beforeStatementAnalysis(BeforeStatementAnalysisEvent $event): ?bool
    {
        $filePath = $event->getStatementsSource()->getFilePath();
        $scopes = self::$pestBindingScopes[$filePath] ?? null;
        if ($scopes === null) {
            return null;
        }

        $context = $event->getContext();
        if (isset($context->vars_in_scope['$this'])) {
            return null;
        }

        $stmt = $event->getStmt();
        $stmtStart = $stmt->getStartFilePos();
        if ($stmtStart < 0) {
            return null;
        }

        foreach ($scopes as [$rangeStart, $rangeEnd, $fqcn]) {
            if ($stmtStart >= $rangeStart && $stmtStart <= $rangeEnd) {
                $thisType = new Union([new TNamedObject($fqcn)]);
                $context->vars_in_scope['$this'] = $thisType;
                $context->vars_possibly_in_scope['$this'] = true;
                // Mirror Psalm's own ClosureAnalyzer behaviour: set $context->self
                // so `self::method()` and `static::method()` resolve to the bound class.
                $context->self = $fqcn;

                return null;
            }
        }

        return null;
    }

    /**
     * @psalm-external-mutation-free
     */
    #[\Override]
    public static function beforeAddIssue(BeforeAddIssueEvent $event): ?bool
    {
        $issue = $event->getIssue();

        if (! $issue instanceof InvalidScope && ! $issue instanceof InternalMethod) {
            return null;
        }

        $filePath = $issue->code_location->file_path;
        if (! (self::$pestFileStatus[$filePath] ?? false)) {
            return null;
        }

        if ($issue instanceof InvalidScope) {
            // `BeforeStatementAnalysis` already injected `$this` into the context for
            // statements inside binding closures, which prevents the cascade where
            // `$this` would otherwise be `mixed` and trip MixedMethodCall everywhere.
            // But Psalm has two InvalidScope emit sites: `VariableFetchAnalyzer`
            // (consults `vars_in_scope`, so injection silences it) and
            // `MethodCallAnalyzer` (checks `$statements_analyzer->getFQCLN()` only,
            // bypassing the context). Closure FQCLN is not mutable from a plugin, so
            // we still need to drop InvalidScope here for binding-closure ranges. The
            // injection makes the subsequent type resolution work; this hook keeps
            // the cosmetic error off the report.
            return self::isInsideBindingClosure($filePath, $issue->code_location->raw_file_start)
                ? false
                : null;
        }

        // Cheap short-circuit before the prefix walk: 99% of InternalMethod issues
        // even inside Pest files reference non-Pest namespaces.
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
     * @psalm-external-mutation-free
     */
    private static function isInsideBindingClosure(string $filePath, int $rawFileStart): bool
    {
        foreach (self::$pestBindingScopes[$filePath] ?? [] as [$start, $end, $_fqcn]) {
            if ($rawFileStart >= $start && $rawFileStart <= $end) {
                return true;
            }
        }

        return false;
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
     * Collect `[start, end, fqcn]` for every closure argument passed to a binding Pest
     * DSL function anywhere in the AST. Walks nested expressions, so an inner
     * `it()`/`test()` inside a `describe()` callback is captured even though `describe`
     * itself is not in the binding list.
     *
     * @param array<Stmt> $stmts
     * @return list<array{int, int, string}>
     */
    private static function collectBindingClosureScopes(array $stmts, string $testCaseClass): array
    {
        $scopes = [];

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
                // Synthetic / un-positioned nodes; cannot range-check against.
                continue;
            }

            $scopes[] = [$start, $end, $testCaseClass];
        }

        return $scopes;
    }

    /**
     * Resolve once per process. `Tests\TestCase` is the Laravel scaffold default and
     * applies to virtually every Laravel + Pest project; `PHPUnit\Framework\TestCase`
     * is the universal fallback. Caching here is safe — TestCase resolution does not
     * depend on which file is being analyzed.
     *
     * @psalm-external-mutation-free
     */
    private static function resolveTestCaseClass(Codebase $codebase): string
    {
        if (self::$resolvedTestCaseClass !== null) {
            return self::$resolvedTestCaseClass;
        }

        foreach (self::TEST_CASE_CANDIDATES as $candidate) {
            if ($codebase->classOrInterfaceExists($candidate)) {
                return self::$resolvedTestCaseClass = $candidate;
            }
        }

        // Both candidates absent (e.g. user runs Psalm without PHPUnit installed).
        // Default to PHPUnit\Framework\TestCase anyway — Psalm will then report
        // UndefinedClass on `$this`, but that's a more accurate signal than the
        // alternative of leaving `$this` unbound and re-introducing the InvalidScope
        // false positive Pest users are trying to avoid.
        return self::$resolvedTestCaseClass = 'PHPUnit\\Framework\\TestCase';
    }
}
