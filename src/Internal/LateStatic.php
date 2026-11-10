<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Internal;

use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Name;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Type\Atomic;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\MutableTypeVisitor;
use Psalm\Type\TypeNode;
use Psalm\Type\Union;

/**
 * @internal
 */
final class LateStatic
{
    /**
     * Whether the receiver is proven NOT late static, so a handler may answer the plain class: `Collection::make()`
     * by name, or an instance typed as a plain object of `$class` (not `$this`, a `static` value or a template).
     * Everything else stays unproven and keeps `&static`: `self::`/`parent::`/`$this::`/`$class::` forward the
     * late static binding, and `$this` refined by `@psalm-if-this-is` names a base class of the called one.
     */
    public static function isPlainReceiver(MethodReturnTypeProviderEvent $event, string $class): bool
    {
        $stmt = $event->getStmt();
        if ($stmt instanceof StaticCall) {
            return $stmt->class instanceof Name && !\in_array(\strtolower($stmt->class->name), ['static', 'self', 'parent'], true);
        }

        if ($stmt->var instanceof Variable && $stmt->var->name === 'this') {
            return false;
        }

        $type = $event->getSource()->getNodeTypeProvider()->getType($stmt->var);
        if (!$type instanceof Union) {
            return false;
        }

        $namesClass = false;
        foreach ($type->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TNull) {
                continue;
            }

            if (!$atomic instanceof TNamedObject || $atomic->is_static || $atomic->extra_types !== []) {
                return false;
            }

            $namesClass = $namesClass || \strtolower($atomic->value) === \strtolower($class);
        }

        return $namesClass;
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
