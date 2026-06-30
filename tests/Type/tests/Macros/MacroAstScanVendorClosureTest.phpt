--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Collection;
use Tests\Psalm\LaravelPlugin\Type\Fixtures\MacroFixtureBag;

/**
 * AST-scan closure type extraction — issue #991.
 *
 * Companion to `MacroDocblockClosureTest.phpt` (issue #989): same docblock
 * narrowing, but the closure lives in `tests/Type/macro-fixtures-vendor-style.php`,
 * a file Psalm does NOT scan (not listed under `<projectFiles>`, `<stubs>`, or
 * the `autoloader` attribute). It only reaches PHP via `require_once` from the
 * autoloader file, mirroring the Inertia / vendor-package call site.
 *
 * The storage-based docblock recovery shipped in #989 misses for these closures
 * (`file_storage_provider->has()` returns `false`). The AST-scan fallback in
 * `CachedClosureTypeFactory::fromClosureObject()` parses the file on demand with
 * `nikic/php-parser`, locates the closure by start line, and lifts `@param`
 * and `@return` Unions from the wrapping `Stmt\Expression`'s docblock into the
 * synthesised pseudo-method.
 *
 * Without this path the macros would resolve as:
 *
 *   astDocblockReturnTest(int):    mixed         (no native return type)
 *   astDocblockGenericTest():      Collection    (generic args erased)
 *
 * With AST recovery they surface as the docblock-narrowed forms below.
 */

function test_ast_scan_recovers_return_type_from_vendor_closure(): string
{
    $_ = (new MacroFixtureBag())->astDocblockReturnTest(3);
    /** @psalm-check-type-exact $_ = non-empty-string */
    return $_;
}

function test_ast_scan_recovers_param_narrowing_from_vendor_closure(): string
{
    // `@param positive-int $count` should narrow the native `int` parameter so
    // passing an `int<1, max>` literal is accepted, while a non-positive literal
    // is rejected (see test_ast_scan_rejects_non_positive_int below).
    $count = 5;
    return (new MacroFixtureBag())->astDocblockReturnTest($count);
}

function test_ast_scan_recovers_generic_return_from_vendor_closure(): Collection
{
    $_ = (new MacroFixtureBag())->astDocblockGenericTest();
    /** @psalm-check-type-exact $_ = Illuminate\Support\Collection<int, string> */
    return $_;
}

function test_ast_scan_partial_param_only_preserves_native_return(): string
{
    // Coverage matrix row 3: docblock narrows `@param positive-int $count` but
    // declares no `@return`. The recovered macro must keep the native `string`
    // return (signature_return_type → return_type fallback) AND apply the
    // narrower param type.
    $_ = (new MacroFixtureBag())->astDocblockParamOnlyTest(2);
    /** @psalm-check-type-exact $_ = string */
    return $_;
}

function test_ast_scan_param_only_still_rejects_non_positive(): string
{
    // Same fixture as above — the docblock-derived `positive-int` narrowing
    // must still reject 0 even when there is no docblock `@return`.
    return (new MacroFixtureBag())->astDocblockParamOnlyTest(-1);
}

function test_ast_scan_recovers_docblock_on_arrow_function(): string
{
    // `fn () => …` (ArrowFunction node) shares the visitor's recovery branch
    // with `Closure`. Locks in that branch.
    $_ = (new MacroFixtureBag())->astDocblockArrowFnTest(4);
    /** @psalm-check-type-exact $_ = non-empty-string */
    return $_;
}

function test_ast_scan_rejects_non_positive_int(): string
{
    return (new MacroFixtureBag())->astDocblockReturnTest(0);
}
?>
--EXPECTF--
InvalidArgument on line %d: Argument 1 of astDocblockParamOnlyTest expects int<1, max>, but -1 provided
InvalidArgument on line %d: Argument 1 of astDocblockReturnTest expects int<1, max>, but 0 provided
