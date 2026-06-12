--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
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
 */
class AddGlobalScopeTestModel extends Model {}

/**
 * The exact repro from issue #1038: self::addGlobalScope called from booted() with a bare
 * `Builder $query` closure param. This is the failure mode — booted() runs in a non-final
 * model's static context, which is where Psalm produces the ModelClass&static intersection.
 */
class BootedGlobalScopeModel extends Model
{
    protected static function booted(): void
    {
        self::addGlobalScope(static function (Builder $query): void {
            $query->where('active', true);
        });
    }
}

/**
 * External static call with bare Builder hint — same fix, different call context.
 */
function test_bare_builder_param_passes(): void
{
    AddGlobalScopeTestModel::addGlobalScope(static function (Builder $query): void {
        $query->where('active', true);
    });
}

/**
 * Explicit Builder<AddGlobalScopeTestModel> hint — works today; must not regress.
 */
function test_explicit_concrete_param_passes(): void
{
    AddGlobalScopeTestModel::addGlobalScope(
        /** @param Builder<AddGlobalScopeTestModel> $query */
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
    AddGlobalScopeTestModel::addGlobalScope(static function (string $query): void {});
}

/**
 * Scope instance form — must stay clean.
 */
function test_scope_instance_passes(Scope $scope): void
{
    AddGlobalScopeTestModel::addGlobalScope($scope);
}

/**
 * Named string + closure form — must stay clean.
 */
function test_named_string_form_passes(): void
{
    AddGlobalScopeTestModel::addGlobalScope('active', static function (Builder $query): void {
        $query->where('active', true);
    });
}
?>
--EXPECTF--
InvalidArgument on line %d: Argument 1 of %s::addGlobalScope expects %s, but impure-Closure(string):void provided
