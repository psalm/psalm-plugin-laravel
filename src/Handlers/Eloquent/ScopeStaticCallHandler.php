<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Model;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\Issue\InvalidStaticInvocation;
use Psalm\Plugin\EventHandler\BeforeAddIssueInterface;
use Psalm\Plugin\EventHandler\Event\BeforeAddIssueEvent;

/**
 * Suppresses InvalidStaticInvocation false positives for #[Scope] methods on Eloquent models.
 *
 * When a model method carries the #[Scope] attribute, it can be called statically at runtime via
 * Model::__callStatic() → query() → Builder::__call() → callNamedScope(). However, Psalm sees
 * these as real instance methods and raises InvalidStaticInvocation before any type provider can
 * intercept — the error fires during the static-method check, before hook dispatch.
 *
 * Legacy scopeXxx() methods are not affected: since active() does not exist as a real method on
 * the model (only scopeActive() does), Psalm never reaches the static-invocation check for them.
 *
 * @internal
 */
final class ScopeStaticCallHandler implements BeforeAddIssueInterface
{
    /** @inheritDoc */
    #[\Override]
    public static function beforeAddIssue(BeforeAddIssueEvent $event): ?bool
    {
        $issue = $event->getIssue();

        if (!$issue instanceof InvalidStaticInvocation) {
            return null;
        }

        // Message format: "Method ClassName::methodName is not static, but is called statically"
        // Anchored to the full message to avoid matching future Psalm variants that share
        // only the "is not static" prefix.
        if (!\preg_match('/^Method (\S+)::(\S+) is not static, but is called statically$/', $issue->message, $matches)) {
            return null;
        }

        $className = $matches[1];
        $methodName = \strtolower($matches[2]);

        $codebase = $event->getCodebase();

        // Only suppress for Eloquent Model subclasses
        try {
            if (!$codebase->classExtends($className, Model::class)) {
                return null;
            }
        } catch (UnpopulatedClasslikeException|\InvalidArgumentException) {
            return null;
        }

        // Only suppress for protected #[Scope] methods. These are the only cases where the
        // static call works at runtime via Model::__callStatic() → query() → Builder::__call():
        //  - public  #[Scope] methods bypass __callStatic entirely and fatal in PHP 8.0+
        //  - private #[Scope] methods cause infinite recursion through __callStatic
        // Legacy scopeXxx() methods never reach this check: since active() does not exist as a
        // real method on the model, Psalm uses a fake-exists path that skips the static check.
        /** @var class-string<Model> $className */
        if (BuilderScopeHandler::hasScopeMethod($codebase, $className, $methodName)
            && BuilderScopeHandler::isProtectedScopeAttributeMethod($codebase, $className, $methodName)
        ) {
            return false;
        }

        return null;
    }
}
