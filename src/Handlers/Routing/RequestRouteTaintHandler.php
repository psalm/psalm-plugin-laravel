<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Routing;

use Illuminate\Http\Request;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\LaravelPlugin\Util\MethodCallerResolver;
use Psalm\Plugin\EventHandler\AddTaintsInterface;
use Psalm\Plugin\EventHandler\Event\AddRemoveTaintsEvent;
use Psalm\Type\TaintKind;

/**
 * Coordinates with {@see RequestRouteReturnTypeProvider} to keep taint
 * accounting correct on `$request->route('name')`.
 *
 * The type provider replaces the stub's return type whenever the registry
 * has any opinion about the parameter name. The mechanism that drops the
 * stub's `@psalm-taint-source` is the early return in
 * {@see \Psalm\Internal\Analyzer\Statements\Expression\Call\Method\MethodCallReturnTypeFetcher::fetch()}:
 * once a type provider returns non-null, the fetcher exits before
 * `taintMethodCallResult` runs, so the stub source is never applied to the
 * outgoing edge. We must put the source back ourselves in the cases where
 * the value is still attacker-controlled (see also vimeo/psalm#11765).
 *
 * Decision table (matches the registry-state branches; the override question
 * is determined by binding||safe-constraint, so it isn't an independent
 * column):
 *
 *   binding | safe constraint | what we re-add
 *   --------|-----------------|----------------
 *      —    |      yes        |  IDENTIFIER_KINDS — constraint genuinely defeats structural-injection sinks, regardless of whether a binding also exists
 *     yes   |      no         |  ALL_INPUT — value originated as a URL segment; the bound Model only paints over that for the type, not for the data flow
 *      no   |      no         |  0 — stub source is still in effect
 *
 * The first row covers both bound+safe and unbound+safe: a route's regex
 * either rules out the relevant sink or it does not — having a binding on top
 * doesn't introduce additional attacker reach. So the constraint-only mask is
 * always the correct (and tighter) choice when a safe constraint is in play.
 *
 * Dynamic parameter names (`$request->route($name)`) and calls outside a
 * Request caller never trigger an override, so they keep the stub source
 * unconditionally. We bail out fast in those branches.
 *
 * Why partial re-addition for safe constraints: {@see SafeRoutePattern}
 * accepts alphanumeric / underscore / dash shapes (`\w+`, `[A-Za-z0-9_-]+`,
 * UUID, ULID, …). Those defeat structural-injection sinks (HTML, SQL,
 * headers, cookies, LDAP, file paths, SSRF, XPath) but NOT identifier-eating
 * sinks: a user-controlled `\w+` is a perfectly valid PHP function name
 * (`call_user_func($req->route('handler'))` ⇒ RCE), include path component,
 * `eval` payload, or extract() key. Shell sinks remain risky too because
 * `[A-Za-z0-9_-]+` admits leading-dash CLI flag injection. The plugin keeps
 * those source kinds tainted; the rest are dropped.
 */
final class RequestRouteTaintHandler implements AddTaintsInterface
{
    /**
     * Sink kinds that survive a "safe constraint" because the regex shapes
     * the plugin recognises don't restrict the value enough to make these
     * targets unreachable. Inverse of the kinds the constraint defeats.
     */
    private const SAFE_CONSTRAINT_RESIDUAL_TAINTS
        = TaintKind::INPUT_CALLABLE
        | TaintKind::INPUT_INCLUDE
        | TaintKind::INPUT_EVAL
        | TaintKind::INPUT_EXTRACT
        | TaintKind::INPUT_SHELL
        | TaintKind::INPUT_LLM_PROMPT;

    /**
     * Decide what taint to apply to `Request::route('literal')` calls based
     * on the route registry's verdict on the parameter name.
     *
     * Bail-out chain is ordered cheapest → most expensive: AST shape and
     * method-name compares run first; the literal-arg type lookup is next
     * (a single `node_data->getType()` hash hit); the caller-class walk
     * (which iterates atomic types and may invoke `Codebase::classExtends`)
     * runs last, only after we've confirmed the call is structurally a
     * candidate for narrowing.
     */
    #[\Override]
    public static function addTaints(AddRemoveTaintsEvent $event): int
    {
        $expr = $event->getExpr();

        if (!$expr instanceof MethodCall || !$expr->name instanceof Identifier) {
            return 0;
        }

        if ($expr->name->name !== 'route') {
            return 0;
        }

        $statementsAnalyzer = $event->getStatementsSource();

        if (!$statementsAnalyzer instanceof StatementsAnalyzer) {
            return 0;
        }

        // Cheap shape check: bail out if the registry has nothing to say
        // about ANY route parameter. Saves the literal-arg lookup AND the
        // caller-class walk for projects that haven't registered any
        // bindings or constraints (testbench fallback, scanner failures,
        // simple apps).
        $registry = RouteParameterRegistry::instance();

        if ($registry->isEmpty()) {
            return 0;
        }

        $name = RouteParameterArg::extractLiteralNameFromCall($expr, $statementsAnalyzer);

        if ($name === null) {
            // Dynamic name — type provider didn't override, stub source stands.
            return 0;
        }

        $hasSafeConstraint = $registry->hasSafeConstraint($name);
        $boundModel = $registry->getBoundModel($name);

        if (!$hasSafeConstraint && $boundModel === null) {
            // Neither binding nor safe constraint → no override → nothing to compensate.
            return 0;
        }

        // We will be applying taint, so confirm the call really lands on a
        // Request (or subclass like FormRequest). Done late because it's the
        // most expensive check: it walks the caller's atomic types and may
        // call Codebase::classExtends per atomic.
        $callerClass = MethodCallerResolver::resolveCallerClass(
            $expr,
            $statementsAnalyzer,
            $event->getCodebase(),
            Request::class,
        );

        if ($callerClass === null) {
            return 0;
        }

        if ($hasSafeConstraint) {
            // Constraint defeats structural-injection sinks; identifier-eating
            // sinks (callable/include/eval/extract/shell) and LLM prompt
            // injection still receive tainted input.
            return self::SAFE_CONSTRAINT_RESIDUAL_TAINTS;
        }

        // Binding known but no safe constraint. The stub's
        // `@psalm-taint-source` was dropped along with the return type, so
        // re-introduce a full input source. Psalm propagates taint through
        // string sinks but does not auto-flow object taint into property
        // reads (e.g. `$model->name`), so this primarily protects
        // stringification/serialization paths (`__toString`, `json_encode`,
        // direct `echo`) where the bound object is consumed as a whole.
        return TaintKind::ALL_INPUT;
    }
}
