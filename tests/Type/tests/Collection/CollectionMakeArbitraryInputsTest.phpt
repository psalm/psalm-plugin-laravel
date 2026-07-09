--FILE--
<?php declare(strict_types=1);

/**
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/808
 *
 * Collection::make() is inherited from Illuminate\Support\Traits\EnumeratesValues, and its
 * `$items` param is widened at that trait (not by re-declaring make() on Collection) so that
 * subclass static calls - e.g. \Illuminate\Database\Eloquent\Collection::make() - resolve the
 * same widened signature. See stubs/common/Support/Traits/EnumeratesValues.phpstub.
 */

use App\Collections\WorkOrderCollection;
use Illuminate\Support\Collection;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;

enum MakeWidgetColor: string
{
    case Red = 'red';
}

final class MakePlainDto
{
    public int $id = 1;
    public string $name = 'dto';
}

$_noArgs = Collection::make();
/** @psalm-check-type-exact $_noArgs = Collection<never, never> */

$_null = Collection::make(null);
/** @psalm-check-type-exact $_null = Collection<never, never>&static */

$_string = Collection::make('str');
/** @psalm-check-type-exact $_string = Collection<0, 'str'>&static */

$_array = Collection::make([1, 2, 3]);
/** @psalm-check-type-exact $_array = Collection<int<0, 2>, 1|2|3> */

/** @var Collection<int, string> $existing */
$existing = new Collection(['x']);
$_fromCollection = Collection::make($existing);
/** @psalm-check-type-exact $_fromCollection = Collection<int, string> */

$_enum = Collection::make(MakeWidgetColor::Red);
/** @psalm-check-type-exact $_enum = Collection<0, MakeWidgetColor>&static */

// Defers to the stub's own inference (the resolver only resolves null/scalar/enum), so this
// loses the &static LSB marker the handler would otherwise attach - a plain object argument
// no longer round-trips through CollectionMakeHandler at all.
$_dto = Collection::make(new MakePlainDto());
/** @psalm-check-type-exact $_dto = Collection<array-key, mixed> */

// Subclass static call: the same widened trait signature must apply here too, not just to
// direct Collection::make() calls (this was the empirically-confirmed dispatch gap, see PR body).
$_eloquentEmpty = EloquentCollection::make([]);
/** @psalm-check-type-exact $_eloquentEmpty = EloquentCollection<never, never> */

$_eloquentString = EloquentCollection::make('str');
/** @psalm-check-type-exact $_eloquentString = EloquentCollection<0, 'str'>&static */

// Chained user-subclass LSB proof: the widened make() signature must preserve the called
// subclass through a subsequent custom-method call, not collapse to the base Collection.
$_workOrderChain = WorkOrderCollection::make('str')->totalLaborHours();
/** @psalm-check-type-exact $_workOrderChain = float */

?>
--EXPECTF--
