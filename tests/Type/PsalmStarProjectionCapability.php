<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Type;

/**
 * Psalm star-projection gate for phpt tests that write a `*` type argument (`Relation<*, Post>`),
 * called from a phpt `--SKIPIF--` section.
 *
 * Released Psalm rejects the syntax, so the fixture can only run against a Psalm that has the
 * feature. The probe is the internal enum Psalm keeps the marker in, because the marker has no
 * public API on purpose.
 *
 * The `--SKIPIF--` script runs in a bare `php` process from the project-root working directory with
 * no autoloader preloaded, so require it via `getcwd()`:
 *
 *   --SKIPIF--
 *   <?php
 *   require getcwd() . '/vendor/autoload.php';
 *   \Tests\Psalm\LaravelPlugin\Type\PsalmStarProjectionCapability::skipUnlessSupported();
 */
final class PsalmStarProjectionCapability
{
    /**
     * Echoes a skip reason and returns true when the installed Psalm has no `*` type arguments;
     * returns false (echoing nothing) when the fixture may proceed.
     */
    public static function skipUnlessSupported(): bool
    {
        if (\enum_exists('Psalm\\Internal\\Type\\TypeArgumentProjection')) {
            return false;
        }

        echo 'skip needs a Psalm with star projections (`*` type arguments)';

        return true;
    }
}
