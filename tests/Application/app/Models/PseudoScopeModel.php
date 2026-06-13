<?php

declare(strict_types=1);

namespace App\Models;

use App\Models\Concerns\HasDocblockScopes;
use Illuminate\Database\Eloquent\Builder;

/**
 * Archetype for scopes that exist ONLY as a class-level `@method scopeXxx()` PHPDoc tag — no
 * concrete scopeXxx() body and no #[Scope] method. Psalm stores these as pseudo_static_methods /
 * pseudo_methods on the model's ClassLikeStorage; the plugin resolves the stripped public form
 * (publishedDoc(), ofTypeDoc(), …) from them, dropping the leading $query a real scope receives,
 * matching Larastan's BuilderHelper. See https://github.com/psalm/psalm-plugin-laravel/issues/1054.
 *
 * These tags are a static-analysis convenience that TRUSTS the developer's assertion; the plugin
 * does not verify a runtime scope exists. A tag with no backing real scopeXxx()/#[Scope] is NOT
 * dispatchable at runtime (Model::hasNamedScope is method_exists-gated, so the bare call throws
 * BadMethodCallException) — this fixture documents the analysis behavior, not a runnable scope.
 *
 * Coverage spread across the three sources Psalm flattens into a model's local pseudo maps:
 *  - direct tags below (scopePublishedDoc, scopeOfTypeDoc, scopeInstanceDoc, scopeOptionalVariadicDoc);
 *  - a `use`d trait (HasDocblockScopes::scopeFromTraitDoc) — the issue's package/trait shape;
 *  - an abstract parent (AbstractPseudoScopeBase::scopeFromParentDoc) — inherited tag.
 *
 * Scopes are conventionally documented as `@method static` (stored in pseudo_static_methods). The
 * instance form (`@method`, no `static`) lands in pseudo_methods instead; the plugin consults
 * both, matching Larastan's getMethodTags(). scopeInstanceDoc() pins that second lookup.
 *
 * Deliberately a BASE-builder model: docblock-only @method scopes on a CUSTOM builder are
 * CustomBuilderMethodHandler's domain (their Builder<static> pseudos are remapped/removed during
 * registration), so this archetype uses the default Eloquent builder. Kept isolated from the
 * shared archetypes (Customer, Vehicle, …) so the pseudo-scope tags cannot perturb other suites.
 *
 * @method static Builder<static> scopePublishedDoc()
 * @method static Builder<static> scopeOfTypeDoc(Builder $query, string $type)
 * @method static Builder<static> scopeOptionalVariadicDoc(Builder $query, string $tag = 'x', string ...$rest)
 * @method Builder<static> scopeInstanceDoc()
 */
final class PseudoScopeModel extends AbstractPseudoScopeBase
{
    use HasDocblockScopes;

    protected $table = 'pseudo_scope_models';
}
