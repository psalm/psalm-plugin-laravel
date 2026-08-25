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

        if (!$returnType instanceof Union) {
            return null;
        }

        // A creator's `: static`/`: self`/`$this` (or a class constant) is anchored to the
        // DECLARING class syntactically but must resolve against the CALLED $receiver — a
        // raw pass-through leaves `static` unresolved and a caller checking against the
        // concrete subclass sees a bogus `Declaring&static` instead of $receiver itself.
        // `final: true` mirrors BuilderScopeHandler::appearingScopeClass()'s reasoning: the
        // called class is already exactly known here, so collapse to the plain class rather
        // than an open-ended intersection.
        return TypeExpander::expandUnion(
            $codebase,
            $returnType,
            $creatorId->fq_class_name,
            $receiver,
            null,
            final: true,
        );
    }

    /**
     * A literal string argument wins; a present-but-non-literal argument (dynamic
     * name, `\UnitEnum` instance) declines; a genuinely MISSING argument falls
     * through to the manager's own default driver.
     */
    private static function resolveDriverName(Codebase $codebase, string $receiver, MethodReturnTypeProviderEvent $event): ?string
    {
        $argType = Arg::typeAt($event->getCallArgs(), $event->getSource(), 0);

        if ($argType instanceof Union) {
            return $argType->isSingleStringLiteral() ? $argType->getSingleStringLiteral()->value : null;
        }

        return self::defaultDriverLiteral($codebase, $receiver);
    }

    /** getDefaultDriver() carries no literal type; only a single `return '...'` body is knowable statically. */
    private static function defaultDriverLiteral(Codebase $codebase, string $receiver): ?string
    {
        $id = self::declaringMethodId($codebase, $receiver, 'getdefaultdriver');

        foreach ((!$id instanceof MethodIdentifier ? null : self::methodBody($codebase, $id)) ?? [] as $stmt) {
            if ($stmt instanceof Stmt\Return_ && $stmt->expr instanceof String_) {
                return $stmt->expr->value;
            }
        }

        return null;
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
