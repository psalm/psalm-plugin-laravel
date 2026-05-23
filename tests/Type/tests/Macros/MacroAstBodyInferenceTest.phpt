--FILE--
<?php declare(strict_types=1);

use Tests\Psalm\LaravelPlugin\Type\Fixtures\MacroFixtureBag;

/**
 * AST body-flow inference — PR #994.
 *
 * Companion to `MacroAstScanVendorClosureTest.phpt`. Same vendor-style file
 * (`macro-fixtures-vendor-style.php`), but the closures registered here have
 * neither a docblock `@return` nor a native return type. The PR's body-flow
 * inference is the only path that can produce a narrower return type than the
 * pseudo-method fallback's `mixed`.
 *
 * Bring the inference back to PHPStan parity:
 *
 *   astBodyInferLiteralStringTest:  () => 'hello'
 *   astBodyInferUnionTest:          () => 1|'x' (multi-return union)
 *   astBodyInferConcatTest:         () => 'ab'  (literal-string folding)
 *   astBodyInferBailsOnComplex:     () => mixed (unhandled expression bails)
 *
 * If body inference regresses, the call sites below will read back the wrong
 * narrowed type and `@psalm-check-type-exact` will fail loud.
 */

function test_ast_body_infer_literal_string(): string
{
    $_ = (new MacroFixtureBag())->astBodyInferLiteralStringTest();
    /** @psalm-check-type-exact $_ = 'hello' */
    return $_;
}

function test_ast_body_infer_multi_return_union(): int|string
{
    $_ = (new MacroFixtureBag())->astBodyInferUnionTest();
    /** @psalm-check-type-exact $_ = 1|'x' */
    return $_;
}

function test_ast_body_infer_bails_on_complex(): mixed
{
    $_ = (new MacroFixtureBag())->astBodyInferBailsOnComplex();
    /** @psalm-check-type-exact $_ = mixed */
    return $_;
}

function test_ast_body_infer_concat_folds_to_literal(): string
{
    $_ = (new MacroFixtureBag())->astBodyInferConcatTest();
    /** @psalm-check-type-exact $_ = 'ab' */
    return $_;
}
?>
--EXPECTF--
