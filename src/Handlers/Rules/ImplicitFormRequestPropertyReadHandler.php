<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Rules;

use PhpParser\Node\Expr\PropertyFetch;
use PhpParser\Node\Identifier;
use Psalm\CodeLocation;
use Psalm\IssueBuffer;
use Psalm\LaravelPlugin\Handlers\Validation\FormRequestPropertyHandler;
use Psalm\LaravelPlugin\Issues\ImplicitFormRequestPropertyRead;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\Type\Union;

/**
 * Opt-in rule: flags an undeclared field read as a magic property on a FormRequest subclass
 * (`$this->email`, `$request->email`) and asks for an explicit validated-input accessor instead.
 *
 * The read resolves through Laravel's `Request::__get`, which reads the raw input bag
 * (`$this->all()`) first and falls back to a route parameter of the same name only when the key is
 * absent from input. So `$request->email` reads **unvalidated** input even on a validated
 * `FormRequest`, where the intent is usually the validated value. Teams that want to minimise this
 * magic enable the rule to require `validated()` / `safe()` / `input()`, which makes the data
 * source obvious to readers and tooling.
 *
 * Registered only when `<reportImplicitFormRequestPropertyReads value="true" />` is set (see
 * {@see \Psalm\LaravelPlugin\Plugin::registerHandlers()}). FormRequest counterpart of
 * {@see ImplicitQueryBuilderCall}, which flags the same "forbid the magic, require the explicit
 * entry point" idea on Eloquent model query/scope forwarding.
 *
 * ## What is flagged
 *
 * Exactly the fetches the plugin itself silently resolves via
 * {@see FormRequestPropertyHandler}'s property providers (#1022): a magic read whose receiver is a
 * FormRequest subclass and whose field has a presence-guaranteeing validation rule but no real or
 * `@property` declaration. The per-field verdict is the shared
 * {@see FormRequestPropertyHandler::resolveRuleForProperty()}, so the type narrowing, the taint
 * path, and this rule agree on exactly which fetches are "magic input reads"; this rule and the
 * taint path additionally share the receiver-union walk via
 * {@see FormRequestPropertyHandler::resolveReceiverRule()}.
 *
 * It deliberately does **not** fire on reads the plugin never narrowed:
 *
 *  - A **declared** member (real property or `@property` / `@property-read`) opts out — the user
 *    made the field concrete, so it is not magic. {@see FormRequestPropertyHandler::resolveRuleForProperty()}
 *    already defers there (via `hasDeclaredProperty()`).
 *  - A field with **no rule, or a non-presence-guaranteeing rule** (`sometimes`, `nullable`) is
 *    left alone — the plugin's `Request` stub omits `__get`, so Psalm already reports such a read
 *    as `UndefinedThisPropertyFetch` / `UndefinedPropertyFetch`. Flagging it here too would
 *    double-report what Psalm already surfaces, mirroring how {@see ImplicitQueryBuilderCall}
 *    defers a genuinely undefined method to `UndefinedMagicMethod`.
 *
 * Because every flagged field guarantees presence, `validated('field')` is always a valid fix
 * (alongside `safe()->field` and `input('field')`).
 *
 * ## Presence-test contexts are not flagged
 *
 * `isset($request->field)` and `unset($request->field)` go through `__isset` (and there is no
 * `__unset`), not a value-returning `__get` read, so they are not magic input reads — the rule
 * defers on them via the
 * `inside_isset` / `inside_unset` analysis context. `empty($request->field)` shares that same
 * `inside_isset` context and is deferred too: it *does* read through `__get`, but Psalm cannot
 * separate it from `isset()`, so deferring it is an accepted, minor false negative — preferable to
 * a false positive on `isset()` / `unset()`, where both the message and the suggested `validated()`
 * fix would be wrong. The null-coalescing read `$request->field ?? ...` is **not** deferred: Psalm
 * does not mark its operand an isset context, so it stays flagged as the real `__get` read it is.
 * The taint source path does **not** apply this filter at all
 * ({@see FormRequestPropertyHandler::resolveReceiverRule()} is context-free), so every real
 * `__get` read — including `empty()` and `??` — stays a taint source.
 *
 * ## Hook choice — AfterExpressionAnalysis
 *
 * Matches {@see ImplicitQueryBuilderCallHandler}: it fires for every expression and detects the
 * magic read off the AST, independent of whether a property provider was consulted. The
 * `instanceof` / `Identifier` guards and the {@see FormRequestPropertyHandler::hasAnyFormRequests()}
 * fast-bail reject the vast majority of expressions before any type lookup; on projects with no
 * FormRequest subclass the rule does no per-expression work at all. Pure-write assignment targets
 * (`$request->field = ...`) are handled by Psalm's AssignmentAnalyzer, which does not route the LHS
 * through this read hook, so a write is never seen here.
 */
final class ImplicitFormRequestPropertyReadHandler implements AfterExpressionAnalysisInterface
{
    /** @inheritDoc */
    #[\Override]
    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        $expr = $event->getExpr();

        // Only instance property reads (`$this->field` / `$request->field`).
        if (!$expr instanceof PropertyFetch) {
            return null;
        }

        // Named property only — `$request->$dynamic` is not statically known.
        if (!$expr->name instanceof Identifier) {
            return null;
        }

        // Fast-bail before any type work: a project with no FormRequest subclass can never match.
        // Also guards runs where FormRequestPropertyHandler never populated its registry.
        if (!FormRequestPropertyHandler::hasAnyFormRequests()) {
            return null;
        }

        // Defer in presence-test / removal contexts: `isset()` / `unset()` invoke `__isset` (and no
        // `__unset`), not `__get`, so they are not magic input reads. `empty()` shares the
        // `inside_isset` context and is deferred too (a deliberate, minor false negative — see the
        // class docblock). `$req->field ?? ...` is not in this context and stays flagged. The taint
        // source path does not apply this filter.
        $context = $event->getContext();

        if ($context->inside_isset || $context->inside_unset) {
            return null;
        }

        $receiverType = $event->getStatementsSource()->getNodeTypeProvider()->getType($expr->var);

        if (!$receiverType instanceof Union) {
            return null;
        }

        // Shared receiver walk: the first receiver atomic that is a FormRequest subclass whose
        // field is a presence-guaranteed, undeclared magic read. Non-FormRequest atomics (e.g. a
        // ValidatedInput from `safe()`) yield no match, so the explicit accessors stay unflagged.
        $match = FormRequestPropertyHandler::resolveReceiverRule($receiverType, $expr->name->name);

        if ($match === null) {
            return null;
        }

        $shortName = self::shortClassName($match[0]);
        $propertyName = $expr->name->name;

        IssueBuffer::accepts(
            new ImplicitFormRequestPropertyRead(
                "{$shortName}::\${$propertyName} is a magic read off the request input bag through "
                . "Laravel's Request::__get. Use validated('{$propertyName}'), safe()->{$propertyName}, "
                . "or input('{$propertyName}') instead.",
                new CodeLocation($event->getStatementsSource(), $expr),
            ),
            $event->getStatementsSource()->getSuppressedIssues(),
        );

        return null;
    }

    /** @psalm-pure */
    private static function shortClassName(string $fqcn): string
    {
        $pos = \strrpos($fqcn, '\\');

        return $pos !== false ? \substr($fqcn, $pos + 1) : $fqcn;
    }
}
