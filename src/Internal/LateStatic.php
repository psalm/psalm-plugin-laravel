<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Internal;

use Psalm\Type\Atomic;
use Psalm\Type\MutableTypeVisitor;
use Psalm\Type\TypeNode;
use Psalm\Type\Union;

/**
 * @internal
 */
final class LateStatic
{
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
