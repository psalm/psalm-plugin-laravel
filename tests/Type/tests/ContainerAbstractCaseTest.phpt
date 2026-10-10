--FILE--
<?php declare(strict_types=1);

// Container keys are case-sensitive, but Psalm's class lookup is not. `schema` is no binding and
// make() cannot build it (the `Schema` facade alias class is abstract), so resolution fails and the
// abstract fallback must not name the `Schema` alias class under the lowercase spelling.

function appHelperDoesNotMatchAliasClassCaseInsensitively(): mixed
{
    /** @psalm-suppress MixedAssignment */
    $schema = app('schema');
    /** @psalm-check-type-exact $schema = mixed */
    return $schema;
}

// A namespaced abstract keeps the lenient match: PHP builds `Illuminate\Validation\Rules\enum` as
// `Enum` (unbound, constructor needs a scalar, so make() throws and the fallback applies).
function appHelperKeepsMisCasedNamespacedClass(): object
{
    $rule = app('Illuminate\Validation\Rules\enum');
    /** @psalm-check-type-exact $rule = Illuminate\Validation\Rules\enum */
    return $rule;
}
?>
--EXPECTF--
