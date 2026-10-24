<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/**
 * A Blade template that failed to compile. Never thrown — returned as a
 * value so a caller can decide how to degrade (skip analysis, log, etc.).
 *
 * @psalm-immutable
 */
final readonly class CompileFailureReason
{
    /**
     * @psalm-mutation-free
     */
    public function __construct(
        public string $templatePath,
        public string $message,
    ) {}

    /**
     * @psalm-pure
     */
    public static function fromThrowable(string $templatePath, \Throwable $e): self
    {
        // One line: it ends up in an issue message.
        $message = \trim((string) \preg_replace('/\s+/', ' ', $e->getMessage()));

        return new self($templatePath, $message === '' ? $e::class : $message);
    }
}
