--FILE--
<?php declare(strict_types=1);

use App\Models\ProtectedizedScopeChild;
use App\Models\ProtectedizedScopeModel;
use App\Models\PublicizedScopeModel;

/**
 * `use HasFlaggedScope { active as public; }` makes the trait's protected #[Scope] accessible, so a static
 * call is not forwarded to __callStatic: PHP throws "Non-static method cannot be called statically". The
 * trait's own declaration stays `protected`, so only the adaptation says it is public. The plugin leaves a
 * method declared protected to Psalm, but must still count this one as public.
 */
function static_call_of_a_publicized_trait_scope(): void
{
    $_result = PublicizedScopeModel::active();
}

/**
 * The reverse: `use HasPublicScope { published as protected; }` narrows a PUBLIC trait scope, so PHP forwards the
 * outside call to `__callStatic` (runtime-valid). Psalm's visibility check reads the trait method's own public
 * visibility, so it still reports InvalidStaticInvocation (a false positive, pinned). The plugin must not hand
 * the method over: Psalm would add an InaccessibleMethod on top, for the model and a child alike.
 */
function static_call_of_a_protectedized_trait_scope(): void
{
    $_model = ProtectedizedScopeModel::published();
    $_child = ProtectedizedScopeChild::published();
}
?>
--EXPECTF--
InvalidStaticInvocation on line %d: Method App\Models\Concerns\HasFlaggedScope::active is not static, but is called statically
InvalidStaticInvocation on line %d: Method App\Models\Concerns\HasPublicScope::published is not static, but is called statically
InvalidStaticInvocation on line %d: Method App\Models\Concerns\HasPublicScope::published is not static, but is called statically
