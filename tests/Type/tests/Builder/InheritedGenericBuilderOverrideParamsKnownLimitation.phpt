--FILE--
<?php declare(strict_types=1);

/**
 * KNOWN LIMITATION — a scope overridden by a descendant with an extra optional parameter, on a builder
 * shared by both models. Psalm asks for the call's params before any receiver-aware provider runs, so
 * `CustomBuilderMethodHandler` validates the call against the ancestor's `scopeVisible($query)` signature,
 * not `InheritedBuilderChild::scopeVisible($query, bool $extra = false)`. Laravel runs the child's scope
 * and accepts the call; the reported issue is an accepted false positive. See the docblock on
 * `CustomBuilderMethodHandler::$builderToModelMap`. #1620
 */

use App\Models\InheritedBuilderChild;

function child_override_with_extra_param(): void
{
    InheritedBuilderChild::query()->visible(true);
}
?>
--EXPECTF--
TooManyArguments on line %d: Too many arguments for App\Builders\InheritedModelBuilder::visible - expecting 0 but saw 1
