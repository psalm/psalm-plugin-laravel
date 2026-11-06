<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use PhpParser\Error as ParseError;
use PhpParser\Node;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use Psalm\Internal\Analyzer\Statements\Expression\ExpressionIdentifier;

/**
 * Reads a shadow's source to tell whether Psalm's `UndefinedMagicPropertyAssignment` twin necessarily
 * fired for a property name: the twin is only emitted when `ExpressionIdentifier::getVarId()` resolves
 * the property fetch, so it is certain exactly when EVERY fetch of that name resolves one.
 *
 * @internal
 */
final class ShadowPropertyAccesses
{
    /**
     * False when any fetch cannot be tied to a resolved var id, or when none exists: the caller then
     * has no proof and must not drop anything. A fetch whose name is not a plain identifier
     * (`$x->{$n}`, `$x->{'p'}`) makes the whole answer false, since Psalm can still resolve such a
     * name to $property for the `__set` call but never gives the fetch a var id.
     */
    public static function allHaveVarId(string $shadowSource, string $property): bool
    {
        try {
            $statements = (new ParserFactory())->createForNewestSupportedVersion()->parse($shadowSource);
        } catch (ParseError) {
            return false;
        }

        $matching = 0;

        foreach ((new NodeFinder())->find($statements ?? [], static fn(Node $node): bool => $node instanceof Node\Expr\PropertyFetch || $node instanceof Node\Expr\NullsafePropertyFetch) as $fetch) {
            if (!$fetch instanceof Node\Expr\PropertyFetch && !$fetch instanceof Node\Expr\NullsafePropertyFetch) {
                continue;
            }

            if (!$fetch->name instanceof Node\Identifier) {
                return false;
            }

            if ($fetch->name->name !== $property) {
                continue;
            }

            // A nullsafe fetch has no var id of its own (it is not a `PropertyFetch`), so one that
            // names the property counts against the answer like any other unresolved receiver.
            if (ExpressionIdentifier::getVarId($fetch, null) === null) {
                return false;
            }

            ++$matching;
        }

        return $matching > 0;
    }
}
