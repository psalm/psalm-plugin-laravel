<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/**
 * A Blade template that failed to compile. Never thrown — returned as a
 * value so a caller can decide how to degrade (skip analysis, log, etc.).
 */
final readonly class BladeCompileError
{
    public function __construct(
        public string $templatePath,
        public string $message,
    ) {}

    public static function fromThrowable(string $templatePath, \Throwable $e): self
    {
        return new self($templatePath, $e::class . ': ' . $e->getMessage());
    }
}
