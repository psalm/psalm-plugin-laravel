--FILE--
<?php declare(strict_types=1);

use App\Models\Mechanic;

/**
 * A relation body whose outermost `as()` the parser cannot read (dynamic or a constant) leaves TAccessor a plain
 * `string`, never an inner literal or `'pivot'`.
 */
function body_accessor_not_provable(Mechanic $mechanic): void
{
    $_dynamic = $mechanic->specializationsDynamicAccessor();
    /** @psalm-trace $_dynamic */

    $_dynamic_first = $mechanic->specializationsDynamicAccessor()->firstOrFail();
    /** @psalm-trace $_dynamic_first */

    $_const = $mechanic->specializationsConstAccessor();
    /** @psalm-trace $_const */

    $_const_first = $mechanic->specializationsConstAccessor()->firstOrFail();
    /** @psalm-trace $_const_first */
}
?>
--EXPECTF--
Trace on line %d: $_dynamic: Illuminate\Database\Eloquent\Relations\BelongsToMany<App\Models\MechanicSpecialization, App\Models\Mechanic, Illuminate\Database\Eloquent\Relations\Pivot, string>
Trace on line %d: $_dynamic_first: App\Models\MechanicSpecialization&object{pivot:Illuminate\Database\Eloquent\Relations\Pivot}
Trace on line %d: $_const: Illuminate\Database\Eloquent\Relations\BelongsToMany<App\Models\MechanicSpecialization, App\Models\Mechanic, Illuminate\Database\Eloquent\Relations\Pivot, string>
Trace on line %d: $_const_first: App\Models\MechanicSpecialization&object{pivot:Illuminate\Database\Eloquent\Relations\Pivot}
