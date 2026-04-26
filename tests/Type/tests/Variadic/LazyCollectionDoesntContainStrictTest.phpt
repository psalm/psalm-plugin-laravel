--SKIPIF--
<?php require getcwd() . '/vendor/autoload.php'; if (!\Composer\InstalledVersions::satisfies(new \Composer\Semver\VersionParser(), 'laravel/framework', '^12.0.0')) { echo 'skip requires Laravel 12+'; }
--FILE--
<?php declare(strict_types=1);

namespace App;

use Illuminate\Support\LazyCollection;

/**
 * LazyCollection::doesntContainStrict() was added in Laravel 12 and accepts
 * variadic arguments via func_get_args(). Without @psalm-variadic the
 * multi-arg call would be rejected as TooManyArguments and the no-arg call
 * must still report TooFewArguments.
 *
 * @param LazyCollection<int, string> $c
 */
function lazy_collection_doesnt_contain_strict_variadic(LazyCollection $c): void
{
    $_single = $c->doesntContainStrict('needle');
    /** @psalm-check-type-exact $_single = bool */

    $_triple = $c->doesntContainStrict('status', '=', 'active');
    /** @psalm-check-type-exact $_triple = bool */
}

/** @param LazyCollection<array-key, mixed> $c */
function lazy_collection_doesnt_contain_strict_too_few(LazyCollection $c): void
{
    $c->doesntContainStrict();
}
?>
--EXPECTF--
TooFewArguments on line %d: Too few arguments for Illuminate\Support\LazyCollection::doesntContainStrict - expecting key to be passed
TooFewArguments on line %d: Too few arguments for method Illuminate\Support\LazyCollection::doesntcontainstrict saw 0
