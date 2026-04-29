--FILE--
<?php declare(strict_types=1);

use App\Models\WorkOrder;

/**
 * Pins the explicit out-of-scope case from issue #760: dynamic relation names
 * (e.g. `$record->{$namePart}()->getRelated()`) cannot be narrowed because the
 * dispatched method is unknown until runtime. The plugin's
 * ModelRelationReturnTypeHandler relies on a literal method-name lookup, so it
 * never fires for variable-method calls — the result stays mixed.
 *
 * If a future change accidentally narrowed this case (e.g. by guessing the
 * relation from the Model alone), the handler would emit a wrong generic and
 * downstream callers would silently lose type safety. This test catches that.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/760
 */

function dynamic_relation_call_remains_mixed(string $name): void
{
    $record = (new WorkOrder())->{$name}();
    $record->getRelated();
}

?>
--EXPECTF--
MixedAssignment on line 22: Unable to determine the type that $record is being assigned to
MixedMethodCall on line 23: Cannot determine the type of $record when calling method getRelated
