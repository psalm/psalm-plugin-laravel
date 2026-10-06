<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade\Fixtures\BoundClosures;

/**
 * Kept out of {@see BoundClosureProvider}: `"${name}"` interpolation is deprecated since PHP 8.2
 * and warns when the file compiles, yet still resolves `${this}` to the bound object.
 */
final class DollarBraceProvider
{
    public function interpolatesDollarBrace(): \Closure
    {
        return function (string $expression): string {
            return "<?php echo '${this}'; ?>";
        };
    }
}
