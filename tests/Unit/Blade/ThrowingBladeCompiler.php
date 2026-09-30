<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;

/**
 * Blade compiler that throws for one template's source. Which real templates make a given Laravel
 * version's compiler throw is version-dependent; the bootstrapper's aggregation only cares that
 * compiling threw.
 */
final class ThrowingBladeCompiler extends BladeCompiler
{
    public function __construct(Filesystem $files, string $cachePath, private readonly string $needle)
    {
        parent::__construct($files, $cachePath);
    }

    /**
     * @param string $value
     *
     * @psalm-suppress MissingParamType,MissingReturnType the parent declares neither, and PHP
     *                 rejects a narrower signature on an untyped parameter
     */
    #[\Override]
    public function compileString($value)
    {
        if (\is_string($value) && \str_contains($value, $this->needle)) {
            throw new \RuntimeException('unexpected end of file');
        }

        return parent::compileString($value);
    }
}
