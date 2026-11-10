--FILE--
<?php declare(strict_types=1);

namespace App\Sandbox\NativeIntersectionReturnOnFinalBuilder;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A native `self&SomeInterface` return on a `final` Eloquent Builder subclass resolves to the intersection.
 * Psalm 7.0 collapsed it to `never` (final class with unpopulated `class_implements` at scan time), which
 * reported InvalidReturnType and InvalidReturnStatement on any body, even `return $this;` (#1450).
 */
interface Marker {}

interface InheritedMarker {}

/**
 * @template TModel of Model
 * @extends Builder<TModel>
 */
abstract class BaseBuilder extends Builder implements InheritedMarker {}

/**
 * @template TModel of Model
 * @extends Builder<TModel>
 */
final class OwnInterfaceBuilder extends Builder implements Marker
{
    public function published(): self&Marker
    {
        return $this;
    }
}

/**
 * @template TModel of Model
 * @extends BaseBuilder<TModel>
 */
final class InheritedInterfaceBuilder extends BaseBuilder
{
    public function published(): self&InheritedMarker
    {
        return $this;
    }
}

function own(OwnInterfaceBuilder $builder): void
{
    $_result = $builder->published();
    /** @psalm-check-type-exact $_result = OwnInterfaceBuilder<Model>&Marker */
}

function inherited(InheritedInterfaceBuilder $builder): void
{
    $_result = $builder->published();
    /** @psalm-check-type-exact $_result = InheritedInterfaceBuilder<Model>&InheritedMarker */
}
?>
--EXPECTF--
