--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Collection;
use Tests\Psalm\LaravelPlugin\Type\Fixtures\MacroFixtureBag;
use Tests\Psalm\LaravelPlugin\Type\Fixtures\MacroFixtureChild;

/**
 * Docblock-aware closure type extraction — issue #899 idea #1 (Strategy C).
 *
 * The `docblockReturnTest` and `docblockGenericTest` macros are registered in
 * `tests/Type/macro-fixtures.php` as closures with docblock annotations that
 * carry MORE information than the closure's native PHP types: `positive-int`
 * narrowing of a `int` parameter, a `non-empty-string` return, and a
 * `Collection<int, string>` generic return.
 *
 * Plain runtime reflection (`ReflectionFunction::getReturnType()` /
 * `getParameters()->getType()`) only surfaces native PHP types, so without
 * docblock recovery these macros would resolve as:
 *
 *   docblockReturnTest(int):  mixed         (no native return type at all)
 *   docblockGenericTest():    mixed         (no native return type)
 *
 * After issue #991 the recovery pipeline tries AST first
 * ({@see \Psalm\LaravelPlugin\Util\Ast\CachedClosureTypeFactory::fromClosureObject()}):
 * the autoloader file is on disk, php-parser parses it on demand, the closure
 * node's own docblock is read directly. The pre-#991 Psalm-storage path
 * ({@see \Psalm\LaravelPlugin\Providers\MacroRegistry::recoverClosureStorage()}
 * / `buildDefinitionFromStorage()`) is still the second-tier fallback for
 * closures whose source AST fails to recover (parse error, ambiguous start
 * line, no usable docblock).
 *
 * The Collection import here matches the import in `macro-fixtures.php`, so the
 * generic-return assertion below resolves against the same FQCN that the
 * pseudo-method's return type carries.
 */

function test_docblock_return_type_recovered_from_closure(): string
{
    $_ = (new MacroFixtureBag())->docblockReturnTest(3);
    /** @psalm-check-type-exact $_ = non-empty-string */
    return $_;
}

function test_docblock_param_type_recovered_from_closure(): string
{
    // The `@param positive-int $count` docblock should narrow the parameter
    // from the native `int` to `positive-int`. Passing a literal `int<1, max>`
    // satisfies the docblock constraint.
    $count = 5;
    return (new MacroFixtureBag())->docblockReturnTest($count);
}

function test_docblock_generic_return_type_recovered_from_closure(): Collection
{
    // Without recovery, the macro's return type would be `mixed` (no native
    // return type on the closure). With recovery, Psalm's docblock parser
    // produces `Collection<int, string>` — the generic shape survives chaining.
    $_ = (new MacroFixtureBag())->docblockGenericTest();
    /** @psalm-check-type-exact $_ = Illuminate\Support\Collection<int, string> */
    return $_;
}

function test_docblock_return_type_recovered_on_static_dispatch(): string
{
    // Macroable defines __callStatic, so docblock-recovered pseudo-methods must land
    // in pseudo_static_methods too. Without this, MacroHandler injecting the storage-
    // recovered definition to only one of the two pseudo-method slots would silently
    // regress static-call dispatch.
    $_ = MacroFixtureBag::docblockReturnTest(3);
    /** @psalm-check-type-exact $_ = non-empty-string */
    return $_;
}

function test_docblock_return_type_recovered_on_subclass_instance(): string
{
    // MacroHandler explicitly propagates pseudo-methods to descendants because
    // MissingMethodCallHandler does not walk parent_classes. Storage-recovered
    // definitions must ride through the same propagation path so a subclass instance
    // still resolves the docblock-narrowed return.
    $_ = (new MacroFixtureChild())->docblockReturnTest(3);
    /** @psalm-check-type-exact $_ = non-empty-string */
    return $_;
}

function test_docblock_param_rejects_non_positive_int(): string
{
    // `positive-int` excludes 0 and negative literals. The narrowed param type
    // should surface in argument validation — a non-positive literal must be
    // rejected with `InvalidArgument` rather than silently accepted as the
    // native `int` parameter would allow.
    return (new MacroFixtureBag())->docblockReturnTest(0);
}
?>
--EXPECTF--
InvalidArgument on line %d: Argument 1 of docblockReturnTest expects int<1, max>, but 0 provided
