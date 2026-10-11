<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/**
 * A successfully compiled shadow file: analyzable PHP content, plus the
 * line map back to the Blade template it was compiled from.
 *
 * @psalm-immutable
 */
final class ShadowResult
{
    /**
     * @param array<int, int>          $lineMap      shadow line (1-based) => blade source line (1-based); 0 for prelude lines
     * @param array<int, list<string>> $suppressions blade line => issue types suppressed there
     */
    public function __construct(
        public readonly string $contents,
        public readonly array $lineMap,
        public readonly ?int $extendsLine,
        public readonly array $suppressions = [],
    ) {}
}
