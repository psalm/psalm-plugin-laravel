--FILE--
<?php declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Admin;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Request;

/** Laravel Nova's NovaRequest shape: inherits the parent's `mixed` docblock and forwards the guard. */
class NovaLikeRequest extends FormRequest
{
    /** {@inheritDoc} */
    #[\Override]
    public function user($guard = null)
    {
        $guard ??= 'web';

        return parent::user($guard);
    }
}

/** Registers itself: its `user()` still resolves to the parent's untyped override. */
final class NovaLikeChildRequest extends NovaLikeRequest
{
}

/** The app's explicit return type wins over the guard's provider model. */
final class AdminRequest extends FormRequest
{
    #[\Override]
    public function user($guard = null): ?Admin
    {
        return Admin::query()->first();
    }
}

trait AdminUserAlias
{
    public function currentUser(?string $guard = null): ?Admin
    {
        return Admin::query()->first();
    }
}

/** An aliased trait method keeps its original name in storage; its declared type still wins. */
final class AliasedAdminRequest extends FormRequest
{
    use AdminUserAlias {
        currentUser as user;
    }
}

/** Parent's typed alias documents the child's untyped alias; Psalm's documented type wins. */
class ParentAliasRequest extends FormRequest
{
    use AdminUserAlias {
        currentUser as user;
    }
}

trait UntypedUserAlias
{
    /**
     * @param string|null $guard
     * @psalm-suppress MissingReturnType
     */
    public function other($guard = null)
    {
        return parent::user($guard);
    }
}

final class ChildAliasRequest extends ParentAliasRequest
{
    use UntypedUserAlias {
        other as user;
    }
}

/** Documents `user()` in a docblock only; a child's `: mixed` signature stays documented by it. */
class DocAdminParentRequest extends FormRequest
{
    /**
     * @param string|null $guard
     * @return Admin|null
     */
    #[\Override]
    public function user($guard = null)
    {
        return Admin::query()->first();
    }
}

final class MixedChildOfDocAdminRequest extends DocAdminParentRequest
{
    #[\Override]
    public function user($guard = null): mixed
    {
        return parent::user($guard);
    }
}

final class PlainFormRequest extends FormRequest
{
}

trait ForwardsUserGuard
{
    /**
     * @param string|null $guard
     * @return mixed
     */
    public function user($guard = null)
    {
        return parent::user($guard);
    }
}

/** A trait-imported override is dispatched under the trait, so registration targets the using class. */
class TraitOverrideRequest extends FormRequest
{
    use ForwardsUserGuard;
}

final class TraitOverrideChildRequest extends TraitOverrideRequest
{
}

/** A `: mixed` signature is documented by `Request::user()`'s `mixed`, so the guard still narrows. */
final class MixedSignatureRequest extends FormRequest
{
    #[\Override]
    public function user($guard = null): mixed
    {
        return parent::user($guard);
    }
}

function overrideWithExplicitGuard(
    NovaLikeRequest $request,
    NovaLikeChildRequest $child,
    TraitOverrideRequest $traitRequest,
    TraitOverrideChildRequest $traitChild,
    MixedSignatureRequest $mixedSignature,
): void {
    $_web = $request->user('web');
    /** @psalm-check-type-exact $_web = \Illuminate\Foundation\Auth\User|null */

    $_identifierName = $request->user('web')?->getAuthIdentifierName();
    /** @psalm-check-type-exact $_identifierName = string|null */

    $_childApi = $child->user('api');
    /** @psalm-check-type-exact $_childApi = \Illuminate\Foundation\Auth\User|null */

    $_traitWeb = $traitRequest->user('web');
    /** @psalm-check-type-exact $_traitWeb = \Illuminate\Foundation\Auth\User|null */

    $_traitChildWeb = $traitChild->user('web');
    /** @psalm-check-type-exact $_traitChildWeb = \Illuminate\Foundation\Auth\User|null */

    $_traitNoArg = $traitRequest->user();
    /** @psalm-check-type-exact $_traitNoArg = mixed */

    $_mixedSignatureWeb = $mixedSignature->user('web');
    /** @psalm-check-type-exact $_mixedSignatureWeb = \Illuminate\Foundation\Auth\User|null */
}

/** The override may pick its own default guard (Nova reads `nova.guard`), so no-arg and null decline. */
function overrideDeclines(NovaLikeRequest $request, string $dynamicGuard): void
{
    $_noArg = $request->user();
    /** @psalm-check-type-exact $_noArg = mixed */

    $_null = $request->user(null);
    /** @psalm-check-type-exact $_null = mixed */

    $_dynamic = $request->user($dynamicGuard);
    /** @psalm-check-type-exact $_dynamic = mixed */

    $_unknown = $request->user('nonexistent-guard');
    /** @psalm-check-type-exact $_unknown = mixed */
}

function overrideWithDeclaredReturnType(
    AdminRequest $request,
    AliasedAdminRequest $aliased,
    ChildAliasRequest $childAlias,
    DocAdminParentRequest $docParent,
    MixedChildOfDocAdminRequest $mixedChild,
): void {
    $_admin = $request->user('web');
    /** @psalm-check-type-exact $_admin = Admin|null */

    $_aliasedWeb = $aliased->user('web');
    /** @psalm-check-type-exact $_aliasedWeb = Admin|null */

    $_childAliasWeb = $childAlias->user('web');
    /** @psalm-check-type-exact $_childAliasWeb = Admin|null */

    $_docParentWeb = $docParent->user('web');
    /** @psalm-check-type-exact $_docParentWeb = Admin|null */

    $_mixedChildWeb = $mixedChild->user('web');
    /** @psalm-check-type-exact $_mixedChildWeb = Admin|null */
}

function requestWithoutOverride(Request $request, PlainFormRequest $formRequest): void
{
    $_requestDefault = $request->user();
    /** @psalm-check-type-exact $_requestDefault = \Illuminate\Foundation\Auth\User|null */

    $_requestWeb = $request->user('web');
    /** @psalm-check-type-exact $_requestWeb = \Illuminate\Foundation\Auth\User|null */

    $_formRequestDefault = $formRequest->user();
    /** @psalm-check-type-exact $_formRequestDefault = \Illuminate\Foundation\Auth\User|null */

    $_formRequestWeb = $formRequest->user('web');
    /** @psalm-check-type-exact $_formRequestWeb = \Illuminate\Foundation\Auth\User|null */
}
?>
--EXPECTF--
