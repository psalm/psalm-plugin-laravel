<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Internal;

use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Name;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\MutableTypeVisitor;
use Psalm\Type\TypeNode;
use Psalm\Type\Union;

/**
 * @internal
 */
final class LateStatic
{
    /**
     * The receiver of the call when it is a single object of `$class` (`$this->count(3)` in a `static`-returning
     * method has `&static`, or the declared `static` return is reported as more specific than what was inferred),
     * or the `static` keyword of `static::times(3)`. Null for a union, a template or a class-string receiver.
     * A handler answers `&static` only when this receiver is late static, as Psalm does for a declared `static`.
     */
    public static function receiver(MethodReturnTypeProviderEvent $event, string $class): ?TNamedObject
    {
        $stmt = $event->getStmt();
        if ($stmt instanceof StaticCall) {
            return $stmt->class instanceof Name && \strtolower($stmt->class->name) === 'static' ? new TNamedObject($class, true) : null;
        }

        $type = $event->getSource()->getNodeTypeProvider()->getType($stmt->var);
        $atomic = $type instanceof Union && $type->isSingle() ? $type->getSingleAtomic() : null;

        return $atomic instanceof TNamedObject && \strtolower($atomic->value) === \strtolower($class) ? $atomic : null;
    }

    /**
     * Drops `&static` (what Collection methods return) at every depth, for output with no class scope
     * to bind it to: a template declaration, or an issue message. Callers that need the result to
     * stay equivalent must first prove the drop only widens the type.
     * Types are copy-on-write: the instance Psalm inferred is never mutated.
     */
    public static function strip(Union $union): Union
    {
        $visitor = new class extends MutableTypeVisitor {
            /**
             * @template T of TypeNode
             * @param T $type
             * @param-out T $type
             * @return MutableTypeVisitor::STOP_TRAVERSAL|MutableTypeVisitor::DONT_TRAVERSE_CHILDREN|null
             * @psalm-suppress ReferenceConstraintViolation T is narrowed to the one atomic rebuilt here
             */
            #[\Override]
            protected function enterNode(TypeNode &$type): ?int
            {
                if ($type instanceof Atomic\TNamedObject) {
                    $type = $type->setIsStatic(false);
                } elseif ($type instanceof Atomic\TDependentGetClass) {
                    // `get_class($x)` keeps the type of `$x` in `as_type`, which Psalm's visitor does not descend into.
                    $inner = $type->as_type;
                    $this->traverse($inner);

                    if ($inner !== $type->as_type) {
                        $type = new Atomic\TDependentGetClass($type->typeof, $inner);
                    }
                }

                return null;
            }
        };

        $visitor->traverse($union);

        return $union;
    }
}
