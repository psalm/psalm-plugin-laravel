<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Pest;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Name;
use PhpParser\Node\Stmt;
use PhpParser\Node\Stmt\Expression;
use PhpParser\Node\Stmt\Namespace_;
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
 *  1. InvalidScope on `$this` inside Pest closures, because Psalm cannot see the runtime
 *     `Closure::bind()` Pest performs in `Pest\TestSuite::test()`.
 *
 *  2. InternalMethod on the Pest DSL surface (`Pest\PendingCalls\*`, `Pest\Mixins\Expectation`,
 *     and `Pest\Expectations\*` higher-order helpers).
 *
 * Detection happens once per file in `beforeAnalyzeFile()` via AST inspection of the parsed
 * top-level statements (Psalm already parsed the file at this point; we read its AST). The
 * result is stored in a per-process static map. The hot `beforeAddIssue()` path is then a
 * single `isset()` lookup against that map, so the handler costs O(1) per issue and zero I/O
 * during analysis. Non-Pest files are unaffected.
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
        // PendingCalls: TestCall, UsesCall, BeforeEachCall, AfterEachCall.
        'pest\\pendingcalls\\',
        // Single class — back of `expect(...)->toX()` calls.
        'pest\\mixins\\expectation::',
        // Higher-order helpers: HigherOrderExpectation, EachExpectation, OppositeExpectation.
        // All three are `@internal` in Pest source.
        'pest\\expectations\\',
    ];

    /**
     * Lowercased Pest DSL function names recognised at the top level of a file as the
     * "this is a Pest test" signature. Matches Pest's own dispatch convention in
     * `Pest\Functions`. `todo()` and `dataset()` are included because they routinely
     * stand alone in a file (placeholder test or extracted dataset definitions) and
     * are the only Pest DSL call those files contain.
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
    ];

    /**
     * Per-file detection results, keyed by the file_path Psalm passes through
     * `StatementsSource::getFilePath()`. Populated lazily during file analysis. Each
     * Psalm worker process (pcntl_fork) keeps its own copy; correctness does not depend
     * on cross-worker sharing.
     *
     * @var array<string, true>
     */
    private static array $pestFilePaths = [];

    /**
     * Inspect each file's parsed top-level statements once. If any is a call to a Pest
     * DSL function in the root namespace, mark the file as Pest. AST inspection is
     * precise — no false positives from comments, strings, or function names that
     * happen to match a Pest DSL identifier elsewhere in the source.
     *
     * A Pest test file may have a namespace declaration (Pest 3 supports this); in that
     * case the file-level statement is a Namespace_ node whose own stmts contain the
     * DSL calls. We walk one level into namespace blocks but no further (nested PHP
     * function bodies are not Pest scope).
     */
    #[\Override]
    public static function beforeAnalyzeFile(BeforeFileAnalysisEvent $event): void
    {
        $filePath = $event->getStatementsSource()->getFilePath();

        if (isset(self::$pestFilePaths[$filePath])) {
            return;
        }

        if (self::containsPestDslCall($event->getStmts())) {
            self::$pestFilePaths[$filePath] = true;
        }
    }

    /**
     * @param array<Stmt> $stmts
     */
    private static function containsPestDslCall(array $stmts): bool
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Namespace_) {
                if (self::containsPestDslCall($stmt->stmts)) {
                    return true;
                }

                continue;
            }

            if ($stmt instanceof Expression && self::isPestDslExpression($stmt->expr)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Walk a top-level expression to find a Pest DSL func call at the root.
     *
     * The Pest DSL is chainable: `uses(...)->in(...)`, `test('a', fn () => null)->with([...])`,
     * `expect($x)->toBe(1)->not->toBeFalse()`. We must unwrap the method-call chain
     * back to its leftmost receiver, which (for a Pest statement) is a `FuncCall`
     * to one of the Pest DSL functions. Anything else (a fluent builder, a
     * static-resolved object) is not Pest.
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

        if (! isset(self::$pestFilePaths[$issue->code_location->file_path])) {
            return null;
        }

        if ($issue instanceof InvalidScope) {
            // `$this` in a Pest test closure is bound to the TestCase at runtime via
            // Closure::bind(). The only other way to trip InvalidScope inside a Pest
            // file is genuinely buggy user code with `$this` in a stray top-level
            // closure, which is rare enough that losing the signal is acceptable.
            return false;
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
}
