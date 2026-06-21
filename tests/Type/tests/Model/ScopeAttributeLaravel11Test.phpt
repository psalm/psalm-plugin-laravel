--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
// Inverse of the Laravel-12 #[Scope] tests (skipBelow('12.0.0')): this asserts the
// Laravel-11 behavior. The #[Scope] attribute class ships with Laravel 12, so on
// Laravel 11 it does not exist. The plugin must report only UndefinedAttributeClass
// and must NOT additionally treat the method as a scope (no PublicModelScope), proving
// EloquentModelMethods::hasScopeAttribute() is version-gated. skipFrom() runs this only
// on Laravel 11.
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipFrom('12.0.0');
--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox;

use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ScopeAttributeL11Model extends Model
{
    // A public #[Scope] would be reported PublicModelScope on Laravel 12. On Laravel 11
    // the attribute does not exist, so only UndefinedAttributeClass must fire. The exact
    // (no-%A) EXPECTF asserts PublicModelScope is absent: it is name-matched, so it would
    // reappear here if the version gate in EloquentModelMethods::hasScopeAttribute() regressed.
    #[Scope]
    public function active(Builder $query): Builder
    {
        return $query;
    }
}
?>
--EXPECTF--
UndefinedAttributeClass on line %d: Attribute class Illuminate\Database\Eloquent\Attributes\Scope does not exist
