<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Helpers;

use Psalm\Plugin\EventHandler\Event\FunctionReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\FunctionReturnTypeProviderInterface;
use Psalm\Type;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TObjectWithProperties;
use Psalm\Type\Union;

/**
 * Infers the return type of Laravel's literal() helper from the call's arguments.
 *
 * Laravel's runtime (Illuminate\Support\helpers.php):
 *
 *     function literal(...$arguments) {
 *         if (count($arguments) === 1 && array_is_list($arguments)) {
 *             return $arguments[0];   // single positional arg → passthrough
 *         }
 *         return (object) $arguments; // otherwise → stdClass with those properties
 *     }
 *
 * - Handler, not stub: property names come from the call's named arguments, not a
 *   fixed signature, so a docblock cannot express the shape.
 * - Object case returns the intersection \stdClass&object{...} (not a bare
 *   object{...}): Psalm's own `(object) $array` cast yields a bare
 *   TObjectWithProperties that is NOT a subtype of \stdClass, so it fails a
 *   \stdClass parameter (ArgumentTypeCoercion). The intersection keeps both the
 *   per-property shape AND \stdClass assignability.
 * - Ordering is load-bearing: \stdClass must be the PRIMARY atomic, the shape the
 *   member (\stdClass&object{...}). Psalm only consults the `&\stdClass` member for
 *   \stdClass-assignability when \stdClass is primary; the reverse
 *   (object{...}&\stdClass) renders fine but still fails ArgumentTypeCoercion.
 *   Property narrowing (literal(a: 1)->a === 1) works under either ordering.
 * - Mirrors Larastan's LiteralExtension, minus its constant-array unpack branch:
 *   `literal(...$x)` property names depend on runtime array contents we cannot
 *   resolve, so we bail to the reflected type.
 */
final class LiteralHandler implements FunctionReturnTypeProviderInterface
{
    /**
     * @inheritDoc
     * @psalm-pure
     */
    #[\Override]
    public static function getFunctionIds(): array
    {
        return ['literal'];
    }

    /** @inheritDoc */
    #[\Override]
    public static function getFunctionReturnType(FunctionReturnTypeProviderEvent $event): ?Union
    {
        $args = $event->getCallArgs();
        $node_type_provider = $event->getStatementsSource()->getNodeTypeProvider();

        // literal() with no args → (object) [] → empty stdClass.
        if ($args === []) {
            return new Union([new TNamedObject(\stdClass::class)]);
        }

        // Single positional, non-unpacked arg → passthrough (the array_is_list path).
        // A single *named* arg (literal(a: 1)) is not a list, so it falls through to
        // the object-shape branch below. A null type here means "unknown" — return
        // null so Psalm keeps the reflected type rather than asserting mixed.
        if (\count($args) === 1 && !$args[0]->name instanceof \PhpParser\Node\Identifier && !$args[0]->unpack) {
            return $node_type_provider->getType($args[0]->value);
        }

        // Unpacking (literal(...$x)) makes the property names depend on the runtime
        // array, which we cannot resolve here — fall back to the reflected type
        // rather than risk an incorrect shape.
        foreach ($args as $arg) {
            if ($arg->unpack) {
                return null;
            }
        }

        // Build object{name|index: type, ...}&\stdClass from the call's arguments.
        // Named args contribute their name; positional args contribute their index
        // (mirroring (object) $arguments over a mixed-key array).
        $properties = [];
        foreach ($args as $index => $arg) {
            $key = $arg->name?->name ?? (string) $index;
            $properties[$key] = $node_type_provider->getType($arg->value) ?? Type::getMixed();
        }

        $std = new TNamedObject(\stdClass::class);

        return new Union([$std->addIntersectionType(new TObjectWithProperties($properties))]);
    }
}
