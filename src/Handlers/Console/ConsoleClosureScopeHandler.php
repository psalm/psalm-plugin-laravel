<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Console;

use Illuminate\Foundation\Console\ClosureCommand;
use Illuminate\Support\Facades\Artisan;
use PhpParser\Node\Expr;
use PhpParser\Node\Expr\ArrowFunction;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use Psalm\Codebase;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\BeforeExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\BeforeStatementAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\BeforeExpressionAnalysisEvent;
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
 * handling of a `$this` var-comment), so we reproduce both:
 *
 * 1. **The `$this` variable's type.**
 *    {@see \Psalm\Internal\Analyzer\ClosureAnalyzer::analyzeExpression()} derives
 *    a closure body's `$this` purely from the *enclosing* `Context::$self`
 *    (`new TNamedObject($context->self)`; it ignores any pre-set `vars_in_scope`).
 *    {@see self::beforeExpressionAnalysis()} sets `Context::$self` to
 *    `ClosureCommand` for the span of the `Artisan::command()` call — the
 *    argument is analysed against that same `Context` (no clone on the facade
 *    `@method` dispatch path) — and {@see self::afterExpressionAnalysis()}
 *    restores it once the call is analysed so no sibling expression sees the
 *    override. (The restore rides Psalm's after-hook, which is skipped if the
 *    call hard-fails analysis; the enclosing statement list is abandoned in that
 *    case, so a still-overridden `self` is never reused, and the after-hook
 *    re-validates the node to stay safe against `spl_object_id` recycling.)
 * 2. **The structural `$this->...` guard.** `MethodCallAnalyzer` (and the
 *    property-fetch analyzer) reject `$this` whenever
 *    `StatementsAnalyzer::getFQCLN()` is null — a separate check that step 1 does
 *    not satisfy, so a `$this->comment()` call would still raise
 *    `InvalidScope: Use of $this in non-class context`.
 *    {@see self::beforeStatementAnalysis()} calls `setFQCLN(ClosureCommand)` on
 *    the closure body's analyzer (detected by `Context::$self` already being
 *    `ClosureCommand`, which only our own step 1 produces), clearing the guard
 *    on a per-body analyzer that is discarded when the closure finishes.
 *
 * **Why a handler and not a stub.** The clean fix lives upstream in Psalm:
 * PHPStan — and therefore Larastan, see its `Foundation/Console/Kernel.stub` —
 * expresses this with a `@param-closure-this ClosureCommand $callback` docblock
 * tag. Psalm has no equivalent tag, so the binding has to be applied
 * imperatively. If Psalm gains `@param-closure-this`, this handler can be
 * replaced by a one-line stub annotation.
 *
 * **Scope.** Covers the `Artisan` facade static call (the documented skeleton
 * form) and the generated `\Artisan` global alias. Two cases are intentionally
 * left alone:
 *
 * - The instance form `$kernel->command(...)` on the concrete
 *   `Illuminate\Foundation\Console\Kernel` (the method is not on the
 *   `Contracts\Console\Kernel` interface) — detection matches static calls only
 *   ({@see self::isArtisanCommandClosureCall()} requires a `StaticCall`), and
 *   this instance form is rare in practice.
 * - A `static function` callback — a static closure cannot be rebound at
 *   runtime, so `$this` inside it is a genuine error. We skip it at detection
 *   ({@see self::hasInlineBindableClosureArg()}), leaving Psalm's `InvalidScope`
 *   to fire as it should.
 *
 * The override spans the whole `command()` call, so in the rare in-method form
 * `Artisan::command(self::SIG, fn ...)` a `self::`/`static::` reference in the
 * signature argument would resolve against ClosureCommand. Harmless in practice:
 * the signature argument is a string literal in real code.
 */
final class ConsoleClosureScopeHandler implements
    BeforeExpressionAnalysisInterface,
    BeforeStatementAnalysisInterface,
    AfterExpressionAnalysisInterface
{
    private const ARTISAN_FACADE = Artisan::class;

    private const CLOSURE_COMMAND = ClosureCommand::class;

    /**
     * Outer `Context::$self` saved per `Artisan::command()` call node (keyed by
     * the node's `spl_object_id`) so it can be restored once the call has been
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

        if (!self::isArtisanCommandClosureCall($expr, $event->getCodebase())) {
            return null;
        }

        // Inject ClosureCommand as the closure's `$this` by overriding the
        // enclosing `self` for the span of this call's analysis. The callback
        // body is analysed within that span, so ClosureAnalyzer picks it up.
        $context = $event->getContext();
        self::$savedSelf[\spl_object_id($expr)] = $context->self;
        $context->self = self::CLOSURE_COMMAND;

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
        // The closure body is the only place that runs with `self` already set to
        // ClosureCommand (we set it solely for `Artisan::command()` callbacks and
        // restore it straight after), so that identity reliably marks "inside a
        // console-command closure". Calling `setFQCLN()` on the body analyzer is
        // exactly what a `/** @var ClosureCommand $this */` docblock does
        // (StatementsAnalyzer sets `fake_this_class` from such a comment), so it
        // clears the structural guard the same sanctioned way — scoped to this
        // body analyzer, which is discarded when the closure finishes.
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

        if (!$expr instanceof StaticCall) {
            return null;
        }

        $id = \spl_object_id($expr);

        // array_key_exists, not isset: the saved value is legitimately null at
        // file scope, which isset() would treat as absent.
        if (!\array_key_exists($id, self::$savedSelf)) {
            return null;
        }

        $saved = self::$savedSelf[$id];
        unset(self::$savedSelf[$id]);

        // Restore `self` only on the node we actually overrode. If
        // `handleExpression()` returned false for an earlier `Artisan::command()`
        // call, its paired after-hook was skipped (Psalm dispatches it only on
        // success), leaving a stale entry; `spl_object_id` can then be recycled
        // onto an unrelated StaticCall. Re-detecting the node — a stable property
        // that always holds for the call we saved — rejects that recycled id so
        // we never write a stale `self` onto someone else's context. Any id the
        // after-hook observes is dropped above, so the map stays bounded — a
        // hard-failed call's own entry lingers only until its id is recycled (or
        // the worker exits), never growing without bound.
        if (self::isArtisanCommandClosureCall($expr, $event->getCodebase())) {
            $event->getContext()->self = $saved;
        }

        return null;
    }

    private static function isArtisanCommandClosureCall(Expr $expr, Codebase $codebase): bool
    {
        if (!$expr instanceof StaticCall) {
            return false;
        }

        // Method names are case-insensitive in PHP; match `command` exactly so
        // siblings like `commandStartedAt()` are not picked up.
        if (!$expr->name instanceof Identifier || \strtolower($expr->name->name) !== 'command') {
            return false;
        }

        // Statically-named receiver only (not `$class::command(...)`).
        if (!$expr->class instanceof Name) {
            return false;
        }

        $className = $expr->class->getAttribute('resolvedName');
        if (!\is_string($className) || !self::resolvesToArtisanFacade($className, $codebase)) {
            return false;
        }

        // Only an inline, non-static closure/arrow function is rebound by Laravel
        // at runtime. A variable holding a callable was analysed in its own scope
        // already, and a `static` closure cannot be rebound — `$this` inside it is
        // a genuine error that must keep surfacing.
        if (!self::hasInlineBindableClosureArg($expr)) {
            return false;
        }

        // ClosureAnalyzer will call classlike_storage_provider->get() on the
        // injected self; bail if ClosureCommand was never scanned to avoid a throw.
        return $codebase->classlike_storage_provider->has(self::CLOSURE_COMMAND);
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

    private static function hasInlineBindableClosureArg(StaticCall $expr): bool
    {
        foreach ($expr->getArgs() as $arg) {
            $value = $arg->value;

            if (($value instanceof Closure || $value instanceof ArrowFunction) && !$value->static) {
                return true;
            }
        }

        return false;
    }
}
