--FILE--
<?php declare(strict_types=1);

use App\Models\Customer;

// A Stringable object also matches the `''` literal arm of a conditional on
// Psalm 7, so blank($stringable) used to infer `true`. Laravel returns false for
// a model, and decides Countable / Stringable objects at runtime (bool).
function probe(Customer $model, ?Customer $maybe, \Stringable $str, \Countable $cnt, mixed $mixed, Customer|string $union, \Stringable|null $maybeStr): void
{
    $_blankModel = blank($model);
    /** @psalm-check-type-exact $_blankModel = false */;
    $_blankMaybe = blank($maybe);
    /** @psalm-check-type-exact $_blankMaybe = bool */;
    $_blankStr = blank($str);
    /** @psalm-check-type-exact $_blankStr = bool */;
    $_blankCnt = blank($cnt);
    /** @psalm-check-type-exact $_blankCnt = bool */;
    $_blankMixed = blank($mixed);
    /** @psalm-check-type-exact $_blankMixed = bool */;
    $_blankUnion = blank($union);
    /** @psalm-check-type-exact $_blankUnion = bool */;
    $_blankMaybeStr = blank($maybeStr);
    /** @psalm-check-type-exact $_blankMaybeStr = bool */;

    $_filledModel = filled($model);
    /** @psalm-check-type-exact $_filledModel = true */;
    $_filledMaybe = filled($maybe);
    /** @psalm-check-type-exact $_filledMaybe = bool */;
    $_filledStr = filled($str);
    /** @psalm-check-type-exact $_filledStr = bool */;
    $_filledCnt = filled($cnt);
    /** @psalm-check-type-exact $_filledCnt = bool */;
    $_filledMixed = filled($mixed);
    /** @psalm-check-type-exact $_filledMixed = bool */;
    $_filledUnion = filled($union);
    /** @psalm-check-type-exact $_filledUnion = bool */;

    $_transformModel = transform($model, fn (Customer $c): int => \count($c->getAttributes()), fn () => 'd');
    /** @psalm-check-type-exact $_transformModel = int<0, max> */;
    $_transformMaybe = transform($maybe, fn (?Customer $c): int => $c === null ? 0 : 1, fn () => 'd');
    /** @psalm-check-type-exact $_transformMaybe = 'd'|0|1 */;
}

// An empty SimpleXMLElement casts to false, so it is not "any other object": blank() / filled() /
// transform() decide at runtime (bool). A final userland object is always truthy.
final class PlainObject
{
}

function probe_falsy_objects(\SimpleXMLElement $xml, PlainObject $plain): void
{
    $_blankXml = blank($xml);
    /** @psalm-check-type-exact $_blankXml = bool */;
    $_blankPlain = blank($plain);
    /** @psalm-check-type-exact $_blankPlain = false */;

    $_filledXml = filled($xml);
    /** @psalm-check-type-exact $_filledXml = bool */;
    $_filledPlain = filled($plain);
    /** @psalm-check-type-exact $_filledPlain = true */;

    $_transformXml = transform($xml, fn (\SimpleXMLElement $_x): int => 1, 'd');
    /** @psalm-check-type-exact $_transformXml = 'd'|1|mixed */;
    $_transformPlain = transform($plain, fn (PlainObject $_p): int => 1, 'd');
    /** @psalm-check-type-exact $_transformPlain = 1 */;
}
?>
--EXPECTF--
