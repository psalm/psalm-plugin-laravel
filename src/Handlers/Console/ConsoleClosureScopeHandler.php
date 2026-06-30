<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Console;

use Illuminate\Foundation\Console\ClosureCommand;
use Illuminate\Support\Facades\Artisan;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\BeforeExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\BeforeFileAnalysisInterface;
use Psalm\Plugin\EventHandler\BeforeStatementAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\BeforeExpressionAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\BeforeFileAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\BeforeStatementAnalysisEvent;

/**
 * Types `$this` inside the closure passed to `Artisan::command()` as
 * {@see \Illuminate\Foundation\Console\ClosureCommand} — the canonical
 * `routes/console.php` pattern that ships in every Laravel skeleton:
 *
 * ```php
 * Artisan::command('inspire', function (): void {
 *     $this->comment('...'); // ClosureCommand, not InvalidScope
 * });
 * ```
 *
 * **Why this is needed.** At runtime Laravel binds the callback to a
 * `ClosureCommand` instance — `ClosureCommand::execute()` runs
 * `$this->callback->bindTo($this, $this)` — so `$this->comment(...)`,
 * `$this->argument(...)`, etc. (mixed in from `Illuminate\Console\Command`)
 * are all valid. Psalm instead sees a free closure written at file scope with
 * no `$this` in context and reports:
 *
 *     InvalidScope - Invalid reference to $this in a non-class context
 *
 * **How it works.** A bound `$this` at file scope needs *two* things, and Psalm
 * gates them separately — exactly the pair a `@var ClosureCommand $this` var
 * docblock would set (see {@see \Psalm\Internal\Analyzer\StatementsAnalyzer}'s
 * handling of a `$this` var-comment), so we reproduce both, scoped to the
 * callback closure node alone:
 *
 * 1. **The `$this` variable's type.**
 *    {@see \Psalm\Internal\Analyzer\ClosureAnalyzer::analyzeExpression()} derives
 *    a closure body's `$this` purely from the *enclosing* `Context::$self`
 *    (`new TNamedObject($context->self)`; it ignores any pre-set `vars_in_scope`).
 *    When {@see self::beforeExpressionAnalysis()} sees an `Artisan::command()`
 *    call it records the `spl_object_id` of its callback argument; when that exact
 *    closure node is then analysed, the same hook sets `Context::$self` to
 *    `ClosureCommand` for that node's span only, and
 *    {@see self::afterExpressionAnalysis()} restores it. Overriding at the closure
 *    node (not the whole call) keeps the *other* arguments on the real `self`, so
 *    `Artisan::command(self::SIG, fn ...)` still resolves `self::SIG` against the
 *    enclosing class.
 * 2. **The structural `$this->...` guard.** `MethodCallAnalyzer` (and the
 *    property-fetch analyzer) reject `$this` whenever
 *    `StatementsAnalyzer::getFQCLN()` is null — a separate check that step 1 does
 *    not satisfy, so a `$this->comment()` call would still raise
 *    `InvalidScope: Use of $this in non-class context`.
 *    {@see self::beforeStatementAnalysis()} calls `setFQCLN(ClosureCommand)` on
 *    the closure body's analyzer (detected by `Context::$self` already being
 *    `ClosureCommand`, which only step 1 produces), clearing the guard on a
 *    per-body analyzer that is discarded when the closure finishes.
 *
 * The recorded callback id is intentionally *not* consumed on first use: the
 * facade `@method` dispatch analyses the callback argument more than once
 * (`AtomicStaticCallAnalyzer::analyzePseudoMethodCall` re-runs `ArgumentsAnalyzer`
 * for the data-flow pass), so each pass must re-apply the override. The id set and
 * the saved-`self` map are cleared per file ({@see self::beforeAnalyzeFile()}),
 * which bounds them and prevents an `spl_object_id` recorded in one file from
 * matching an unrelated closure in another.
 *
 * **Why a handler and not a stub.** The clean fix lives upstream in Psalm:
 * PHPStan — and therefore Larastan, see its `Foundation/Console/Kernel.stub` —
 * expresses this with a `@param-closure-this ClosureCommand $callback` docblock
 * tag. Psalm has no equivalent tag yet — it is being added in
 * {@link https://github.com/vimeo/psalm/pull/11853} (closes
 * {@link https://github.com/vimeo/psalm/issues/11851}). Once that ships in the
 * plugin's Psalm version, retire this handler: delete it and its
 * {@see \Psalm\LaravelPlugin\Plugin} registration, and add a
 * `@param-closure-this \Illuminate\Foundation\Console\ClosureCommand $callback`
 * stub on the `command()` signature (the Larastan one-liner).
 *
 * **Scope.** Covers the `Artisan` facade static call (the documented skeleton
 * form) and the generated `\Artisan` global alias. Two cases are intentionally
 * left alone:
 *
 * - The instance form `$kernel->command(...)` on the concrete
 *   `Illuminate\Foundation\Console\Kernel` (the method is not on the
 *   `Contracts\Console\Kernel` interface) — detection matches static calls only
 *   ({@see self::callbackToBind()} requires a `StaticCall`), and this instance
 *   form is rare in practice.
 * - A `static function`/`static fn` callback — a static closure cannot be
 *   rebound at runtime, so `$this` inside it is a genuine error. We skip it at
 *   detection ({@see self::bindableCallbackArg()}), leaving Psalm's
 *   `InvalidScope` to fire as it should.
 */
final class ConsoleClosureScopeHandler implements
    BeforeExpressionAnalysisInterface,
    BeforeStatementAnalysisInterface,
    AfterExpressionAnalysisInterface,
    BeforeFileAnalysisInterface
{
    private const ARTISAN_FACADE = Artisan::class;

    private const CLOSURE_COMMAND = ClosureCommand::class;

    /**
     * `spl_object_id`s of callback closure nodes seen as the callback argument of
     * an `Artisan::command()` call in the file currently being analysed. Recorded
     * when the call is visited and matched when the closure node is analysed (kept,
     * not consumed: the callback is analysed more than once per call). Cleared in
     * {@see self::beforeAnalyzeFile()}.
     *
     * @var array<int, true>
     */
    private static array $callbackIds = [];

    /**
     * Outer `Context::$self` saved per overridden callback closure node (keyed by
     * the node's `spl_object_id`) so it can be restored once the closure has been
     * analysed. Values are nullable because the dominant call site is file scope
     * (`routes/console.php`), where `self` is null.
     *
     * @var array<int, ?string>
     */
    private static array $savedSelf = [];

    #[\Override]
    public static function beforeExpressionAnalysis(BeforeExpressionAnalysisEvent $event): ?bool
    {
        $expr = $event->getExpr();

        // First pass: an Artisan::command() call — remember its callback node so
        // the override fires on that node alone (below), not across sibling args.
        if ($expr instanceof StaticCall) {
            $callback = self::callbackToBind($expr, $event->getCodebase());
            if ($callback !== null) {
                self::$callbackIds[\spl_object_id($callback)] = true;
            }

            return null;
        }

        // The callback closure node itself — override `self` so ClosureAnalyzer
        // types the body `$this` as ClosureCommand.
        if ($expr instanceof Closure || $expr instanceof ArrowFunction) {
            $context = $event->getContext();

            // Skip non-callbacks, and skip when our override is already in place:
            // the callback is analysed more than once, and a re-entrant call must
            // not capture ClosureCommand itself as the "outer" self to restore.
            if (!isset(self::$callbackIds[\spl_object_id($expr)])
                || $context->self === self::CLOSURE_COMMAND
            ) {
                return null;
            }

            self::$savedSelf[\spl_object_id($expr)] = $context->self;
            $context->self = self::CLOSURE_COMMAND;
        }

        return null;
    }

    /**
     * `setFQCLN()` is the only side effect, and Psalm models it as
     * external-mutation-free (it touches the analyzer's own `fake_this_class`),
     * so the method analyses as pure even though it steers later analysis.
     *
     * @psalm-external-mutation-free
     */
    #[\Override]
    public static function beforeStatementAnalysis(BeforeStatementAnalysisEvent $event): ?bool
    {
        // Setting `self` above types the `$this` *variable* (ClosureAnalyzer builds
        // it from `self`), but `$this->method()` / `$this->prop` are gated by a
        // second, structural guard — `MethodCallAnalyzer` / property fetch check
        // `StatementsAnalyzer::getFQCLN()`, which stays null for a file-scope
        // closure and emits `InvalidScope: Use of $this in non-class context`.
        //
        // The callback body is the only place that runs with `self` set to
        // ClosureCommand (we set it solely on the registered callback node), so
        // that identity reliably marks "inside a console-command closure". Calling
        // `setFQCLN()` on the body analyzer is exactly what a
        // `/** @var ClosureCommand $this */` docblock does (StatementsAnalyzer sets
        // `fake_this_class` from such a comment), so it clears the structural guard
        // the same sanctioned way — scoped to this body analyzer, which is
        // discarded when the closure finishes.
        if ($event->getContext()->self !== self::CLOSURE_COMMAND) {
            return null;
        }

        $source = $event->getStatementsSource();
        if ($source instanceof StatementsAnalyzer) {
            $source->setFQCLN(self::CLOSURE_COMMAND);
        }

        return null;
    }

    #[\Override]
    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        $expr = $event->getExpr();

        if (!$expr instanceof Closure && !$expr instanceof ArrowFunction) {
            return null;
        }

        $id = \spl_object_id($expr);

        // array_key_exists, not isset: the saved value is legitimately null at
        // file scope, which isset() would treat as absent.
        if (!\array_key_exists($id, self::$savedSelf)) {
            return null;
        }

        $context = $event->getContext();

        // Restore only while our override is still live. If the closure hard-failed
        // analysis Psalm skips this after-hook; the `self === ClosureCommand` guard
        // means a later node reusing this id (after the entry leaked) drops the
        // stale entry below without being clobbered.
        if ($context->self === self::CLOSURE_COMMAND) {
            $context->self = self::$savedSelf[$id];
        }

        unset(self::$savedSelf[$id]);

        return null;
    }

    /**
     * Per-file reset, at file start. Bounds the static maps and ensures an
     * `spl_object_id` recorded for one file's callback cannot match an unrelated
     * closure node in the next file (ids are unique only among live objects).
     * Resetting at the *start* (rather than end) keeps it correct even if a prior
     * file's analysis threw before any end-of-file hook could run.
     *
     * @psalm-external-mutation-free
     */
    #[\Override]
    public static function beforeAnalyzeFile(BeforeFileAnalysisEvent $event): void
    {
        self::$callbackIds = [];
        self::$savedSelf = [];
    }

    /**
     * The inline, bindable callback of an `Artisan::command()` static call, or
     * null when this is not such a call. Cheap AST checks (method name, named
     * receiver, the callback argument) run before the codebase queries so
     * unrelated `::command()` calls bail early.
     */
    private static function callbackToBind(StaticCall $expr, Codebase $codebase): Closure|ArrowFunction|null
    {
        // Method names are case-insensitive in PHP; match `command` exactly so
        // siblings like `commandStartedAt()` are not picked up.
        if (!$expr->name instanceof Identifier || \strtolower($expr->name->name) !== 'command') {
            return null;
        }

        // Statically-named receiver only (not `$class::command(...)`).
        if (!$expr->class instanceof Name) {
            return null;
        }

        $callback = self::bindableCallbackArg($expr);
        if ($callback === null) {
            return null;
        }

        $className = $expr->class->getAttribute('resolvedName');
        if (!\is_string($className) || !self::resolvesToArtisanFacade($className, $codebase)) {
            return null;
        }

        // ClosureAnalyzer will call classlike_storage_provider->get() on the
        // injected self; bail if ClosureCommand was never scanned to avoid a throw.
        if (!$codebase->classlike_storage_provider->has(self::CLOSURE_COMMAND)) {
            return null;
        }

        return $callback;
    }

    /** @psalm-external-mutation-free */
    private static function resolvesToArtisanFacade(string $className, Codebase $codebase): bool
    {
        if ($className === self::ARTISAN_FACADE) {
            return true;
        }

        // The generated `\Artisan` global alias stub extends the real facade.
        return $codebase->classExists($className)
            && $codebase->classExtends($className, self::ARTISAN_FACADE);
    }

    /**
     * The `command(string $signature, Closure $callback)` callback argument — the
     * 2nd positional arg or a named `callback:` arg — when it is an inline,
     * non-static closure/arrow function (the only form Laravel rebinds). Any other
     * argument (including a closure passed as the signature) is ignored.
     */
    private static function bindableCallbackArg(StaticCall $expr): Closure|ArrowFunction|null
    {
        $positional = 0;

        foreach ($expr->getArgs() as $arg) {
            $isCallback = $arg->name instanceof Identifier
                ? $arg->name->name === 'callback'
                : $positional++ === 1;

            if (!$isCallback) {
                continue;
            }

            $value = $arg->value;

            return ($value instanceof Closure || $value instanceof ArrowFunction) && !$value->static
                ? $value
                : null;
        }

        return null;
    }
}
