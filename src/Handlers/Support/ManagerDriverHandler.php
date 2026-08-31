<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Support;

use Illuminate\Support\Manager;
use Illuminate\Support\Str;
use PhpParser\Node\Scalar\String_;
use PhpParser\Node\Stmt;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\Internal\MethodIdentifier;
use Psalm\Internal\Type\TypeExpander;
use Psalm\LaravelPlugin\Internal\Arg;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type\Union;

/**
 * Narrows userland `Manager::driver()` to the DECLARED return type of the matching
 * `create{Studly}Driver()` (#1392); Laravel's own `driver()` is untyped (`@return
 * mixed`). Dispatch falls back from the called class to the DECLARING class only
 * when the called class doesn't itself declare the method, so registering on
 * `Manager::class` alone covers every non-overriding subclass; an override of
 * `driver()` itself shadows this handler (accepted FN, see
 * ManagerDriverOverrideShadowKnownLimitation.phpt).
 *
 * Accepted runtime imprecision that stays OUT of scope: `Manager::extend()`
 * registering a custom creator closure, and the `$this->drivers[$name]` cache
 * pre-populated some other way, both take precedence over `create{X}Driver()` at
 * runtime and neither is statically provable from the call site — narrowing here
 * assumes the stock creator-method dispatch path Laravel documents.
 *
 * @internal
 */
final class ManagerDriverHandler implements MethodReturnTypeProviderInterface
{
    /**
     * @return list<string>
     * @psalm-pure
     */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return [Manager::class];
    }

    /** @inheritDoc */
    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Union
    {
        if ($event->getMethodNameLowercase() !== 'driver') {
            return null;
        }

        $codebase = $event->getSource()->getCodebase();
        $receiver = $event->getCalledFqClasslikeName() ?? $event->getFqClasslikeName();

        // Laravel's createDriver() dispatches to $this->createDriver($name), which a
        // subclass (e.g. ChannelManager, ImageManager) may override wholesale. Once that
        // happens, finding create{X}Driver() on $receiver no longer proves it is what
        // actually runs — decline rather than trust a lookup the override can bypass.
        $createDriverId = self::declaringMethodId($codebase, $receiver, 'createdriver');

        if ($createDriverId instanceof MethodIdentifier && \strcasecmp($createDriverId->fq_class_name, Manager::class) !== 0) {
            return null;
        }

        $driverName = self::resolveDriverName($codebase, $receiver, $event);

        if ($driverName === null) {
            return null;
        }

        $creator = \strtolower('create' . Str::studly($driverName) . 'Driver');
        $creatorId = self::declaringMethodId($codebase, $receiver, $creator);

        if (!$creatorId instanceof MethodIdentifier) {
            return null; // no matching create{X}Driver() — decline, never guess
        }

        try {
            $returnType = $codebase->methods->getStorage($creatorId)->return_type;
        } catch (\UnexpectedValueException) {
            return null;
        }

        if (!$returnType instanceof Union || $returnType->isVoid() || $returnType->isNever()) {
            return null; // void/never/absent: the wrapper's real value is null, not this
        }

        // A creator's `: static`/`: self`/`$this` (or a class constant) is anchored to the
        // class it APPEARS ON, not necessarily the class it's DECLARED in — a trait-provided
        // creator's declaring class is the trait itself, and expanding `self` against the
        // trait would leak the trait's name as a type. appearing_method_ids resolves that to
        // the actual composing class (identical to the declaring class for a non-trait
        // creator). `final: true` mirrors BuilderScopeHandler::appearingScopeClass()'s
        // reasoning: the called class is already exactly known here ($receiver), so `static`
        // collapses to the plain class rather than an open-ended intersection.
        $selfClass = self::appearingMethodId($codebase, $receiver, $creator)?->fq_class_name ?? $creatorId->fq_class_name;

        return TypeExpander::expandUnion(
            $codebase,
            $returnType,
            $selfClass,
            $receiver,
            null,
            final: true,
        );
    }

    /**
     * A literal string argument wins; a present-but-non-literal argument (dynamic
     * name, `\UnitEnum` instance) declines; a genuinely MISSING argument falls
     * through to the manager's own default driver. Laravel resolves the argument
     * with `enum_value($driver) ?: $this->getDefaultDriver()`: a FALSY literal
     * (`''` or `'0'`) is therefore "no driver given" too, not a literal name.
     */
    private static function resolveDriverName(Codebase $codebase, string $receiver, MethodReturnTypeProviderEvent $event): ?string
    {
        $argType = Arg::typeAt($event->getCallArgs(), $event->getSource(), 0);

        if ($argType instanceof Union) {
            if (!$argType->isSingleStringLiteral()) {
                return null;
            }

            $value = $argType->getSingleStringLiteral()->value;

            if ($value !== '' && $value !== '0') {
                return $value;
            }
        }

        return self::defaultDriverLiteral($codebase, $receiver);
    }

    /**
     * getDefaultDriver() carries no literal type; a body of exactly ONE
     * `return '...'` statement is knowable statically. Anything else — a
     * conditional, extra statements, a second reachable return — means a
     * DIFFERENT literal can come back depending on state we cannot see, so
     * that whole shape declines rather than picking one branch to trust.
     */
    private static function defaultDriverLiteral(Codebase $codebase, string $receiver): ?string
    {
        $id = self::declaringMethodId($codebase, $receiver, 'getdefaultdriver');
        $stmts = $id instanceof MethodIdentifier ? self::methodBody($codebase, $id) : null;

        if ($stmts === null || \count($stmts) !== 1) {
            return null;
        }

        $stmt = \reset($stmts);

        return $stmt instanceof Stmt\Return_ && $stmt->expr instanceof String_ ? $stmt->expr->value : null;
    }

    /** @psalm-mutation-free */
    private static function declaringMethodId(Codebase $codebase, string $receiver, string $methodNameLower): ?MethodIdentifier
    {
        try {
            $storage = $codebase->classlike_storage_provider->get(\strtolower($receiver));
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $storage->declaring_method_ids[$methodNameLower] ?? null;
    }

    /**
     * The class a method APPEARS ON — the composing class for a trait-provided
     * method, identical to the declaring class otherwise. Used only to anchor
     * `self`/`static`/class-constant expansion correctly for trait creators.
     *
     * @psalm-mutation-free
     */
    private static function appearingMethodId(Codebase $codebase, string $receiver, string $methodNameLower): ?MethodIdentifier
    {
        try {
            $storage = $codebase->classlike_storage_provider->get(\strtolower($receiver));
        } catch (\InvalidArgumentException) {
            return null;
        }

        return $storage->appearing_method_ids[$methodNameLower] ?? null;
    }

    /**
     * AST-parses the declaring class's source to read a method body without
     * invoking it (precedent: CastsMethodParser).
     *
     * @return ?array<array-key, Stmt>
     */
    private static function methodBody(Codebase $codebase, MethodIdentifier $id): ?array
    {
        try {
            $location = $codebase->methods->getStorage($id)->location;
        } catch (\UnexpectedValueException) {
            return null;
        }

        if (!$location instanceof CodeLocation) {
            return null;
        }

        try {
            $fileStmts = $codebase->getStatementsForFile($location->file_path);
        } catch (\InvalidArgumentException|\UnexpectedValueException) {
            return null;
        }

        return self::findMethod($fileStmts, '', $id->fq_class_name, $id->method_name);
    }

    /**
     * Walk namespace → class → method manually (Psalm's AST has no parent links).
     *
     * @param array<array-key, Stmt> $stmts
     * @return ?array<array-key, Stmt>
     * @psalm-mutation-free
     */
    private static function findMethod(array $stmts, string $nsPrefix, string $fqClassName, string $methodNameLower): ?array
    {
        foreach ($stmts as $stmt) {
            if ($stmt instanceof Stmt\Namespace_) {
                $found = self::findMethod($stmt->stmts, $stmt->name?->toString() ?? '', $fqClassName, $methodNameLower);
                if ($found !== null) {
                    return $found;
                }

                continue;
            }

            if (!$stmt instanceof Stmt\ClassLike) {
                continue;
            }

            $shortName = $stmt->name?->toString() ?? '';
            $fqcn = $nsPrefix !== '' ? $nsPrefix . '\\' . $shortName : $shortName;

            if (\strcasecmp($fqcn, $fqClassName) !== 0) {
                continue;
            }

            foreach ($stmt->stmts as $member) {
                if ($member instanceof Stmt\ClassMethod && \strtolower((string) $member->name) === $methodNameLower) {
                    return $member->stmts;
                }
            }
        }

        return null;
    }
}
