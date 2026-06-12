--FILE--
<?php declare(strict_types=1);

use App\Models\Tool;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Scope;

/**
 * Regression tests for psalm/psalm-plugin-laravel#1038.
 *
 * addGlobalScope() accepts a Closure whose first param is Builder<static>. For non-final
 * models Psalm expands `static` to `ModelClass&static`, producing Builder<ModelClass&static>
 * as the expected closure param. A bare `Builder $query` hint gets Builder<static> via template
 * inference — the &static intersection on the expected side causes unification to fail.
 *
 * The plugin fixes this by re-expanding the formal params of addGlobalScope via
 * TypeExpander::expandUnion with final: true, pinning Builder<static> to Builder<ModelClass>
 * on the expected side so both sides resolve to the same concrete type.
 *
 * Uses the autoloadable Tool archetype so the per-model handler is registered and the fix
 * actually applies (inline sandbox models are skipped by ModelRegistrationHandler).
 */

/**
 * Bare `Builder $query` hint — the canonical issue form. Must produce no error.
 */
function test_bare_builder_param_passes(): void
{
    Tool::addGlobalScope(static function (Builder $query): void {
        $query->where('active', true);
    });
}

/**
 * Explicit Builder<Tool> hint — works today; must not regress.
 */
function test_explicit_concrete_param_passes(): void
{
    Tool::addGlobalScope(
        /** @param Builder<Tool> $query */
        static function (Builder $query): void {
            $query->where('active', true);
        },
    );
}

/**
 * Wrong closure param type — must still produce InvalidArgument.
 */
function test_wrong_param_type_errors(): void
{
    Tool::addGlobalScope(static function (string $query): void {
        echo $query;
    });
}

/**
 * Scope instance form — must stay clean.
 */
function test_scope_instance_passes(Scope $scope): void
{
    Tool::addGlobalScope($scope);
}

/**
 * Named string + closure form — must stay clean.
 */
function test_named_string_form_passes(): void
{
    Tool::addGlobalScope('active', static function (Builder $query): void {
        $query->where('active', true);
    });
}
?>
--EXPECTF--
InvalidArgument on line %d: Argument 1 of App\Models\Tool::addGlobalScope expects %s, but impure-Closure(string):void provided
