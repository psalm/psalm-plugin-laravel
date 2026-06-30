--FILE--
<?php declare(strict_types=1);

/**
 * Regression: app()->bound('<unknown>') must return bool, not narrow to literal false.
 *
 * invoiceninja's AppServiceProvider checks `if (! app()->bound('sentry'))` to skip an
 * optional Sentry integration. Earlier triage of the original error
 * `TypeDoesNotContainType - Operand of type false is always falsy` suggested the
 * plugin might over-narrow `Container::bound()` to `false` for abstracts not present
 * in the Testbench-booted container. Pin this to bool so a future regression there
 * (e.g. an overzealous container-introspection handler) is caught immediately.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/942
 */
function test_app_bound_unknown_abstract_is_bool(): void
{
    $_result = app()->bound('sentry');
    /** @psalm-check-type-exact $_result = bool */
}

// And ! bound(...) must not collapse to a literal-true / literal-false constant,
// otherwise the surrounding early-return narrowing fires TypeDoesNotContainType.
function test_negated_app_bound_supports_early_return(): void
{
    if (! app()->bound('sentry')) {
        return;
    }
    // Reachable: no "Operand of type false is always falsy" or unreachable-branch error.
    $_y = app()->bound('sentry');
    /** @psalm-check-type-exact $_y = bool */
}

?>
--EXPECTF--
