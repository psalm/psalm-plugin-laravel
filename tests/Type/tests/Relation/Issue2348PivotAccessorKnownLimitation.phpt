--FILE--
<?php declare(strict_types=1);

use App\Models\Mechanic;

/**
 * Accepted gaps of the accessor rename, see the PivotAccessorHandler docblock (#2348).
 *
 * - Callback params keep `pivot`: Psalm types a closure argument before the call resolves.
 * - A first-class callable skips the after-call hook, so its return keeps `pivot`.
 * - A `firstOr()` callback returning the same model and pivot type is renamed with the relation's own result:
 *   the two branches are one type, and the callback's value really carries `pivot`.
 */
function accessor_known_limitations(Mechanic $mechanic): void
{
    $mechanic->specializationsAccessorOnly()->each(static function ($specialization): void {
        /** @psalm-trace $specialization */
    });

    $mechanic->specializationsAccessorOnly()->chunk(10, static function ($chunk): void {
        /** @psalm-trace $chunk */
    });

    $_first_class_callable = $mechanic->specializationsAccessorOnly()->firstOrFail(...);
    /** @psalm-trace $_first_class_callable */

    $_first_or_same_shape = $mechanic->specializationsAccessorOnly()
        ->firstOr(static fn () => $mechanic->specializations()->firstOrFail());
    /** @psalm-trace $_first_or_same_shape */
}
?>
--EXPECTF--
UnusedClosureParam on line %d: Param specialization is never referenced in this method
Trace on line %d: $specialization: App\Models\MechanicSpecialization&object{pivot:Illuminate\Database\Eloquent\Relations\Pivot}
UnusedClosureParam on line %d: Param chunk is never referenced in this method
Trace on line %d: $chunk: Illuminate\Database\Eloquent\Collection<int, App\Models\MechanicSpecialization&object{pivot:Illuminate\Database\Eloquent\Relations\Pivot}>
Trace on line %d: $_first_class_callable: Closure[impure](%s):App\Models\MechanicSpecialization&object{pivot:Illuminate\Database\Eloquent\Relations\Pivot}
Trace on line %d: $_first_or_same_shape: App\Models\MechanicSpecialization&object{details:Illuminate\Database\Eloquent\Relations\Pivot}
