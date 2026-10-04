--FILE--
<?php declare(strict_types=1);

/**
 * KNOWN LIMITATION — a scope overridden by a descendant with an extra optional parameter, on a builder
 * shared by both models. Psalm asks for the call's params before any receiver-aware provider runs, so
 * `CustomBuilderMethodHandler::getScopeMethodParamsOnBuilder()` answers with the most-derived model's
 * signature (`InheritedBuilderChild::scopeVisible($query, bool $extra = false)`). A base receiver passing
 * the child-only argument is therefore accepted, although Laravel would run the base scope and ignore it.
 * Accepted because PHP override compatibility makes the deepest signature a superset of its ancestors':
 * the handler can miss this error but never invent one. See the docblock on that method. #1620
 */

use App\Models\InheritedBuilderModel;

function base_receiver_accepts_child_only_scope_argument(): void
{
    InheritedBuilderModel::query()->visible(true);
}
?>
--EXPECTF--
