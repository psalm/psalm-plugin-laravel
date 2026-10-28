<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Bootstrap;

/**
 * The project has no `bootstrap/app.php` to boot Laravel from, and Orchestra Testbench, the fallback for Laravel
 * packages, is not installed.
 *
 * @internal
 */
final class TestbenchMissingException extends \RuntimeException {}
