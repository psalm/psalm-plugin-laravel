<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/**
 * Blade analysis disabled itself at boot while `failOnInternalError` is on. The message names the
 * cause; the previous throwable, when there is one, is the crash that caused it.
 *
 * @internal
 */
final class BladeDisabledException extends \RuntimeException {}
