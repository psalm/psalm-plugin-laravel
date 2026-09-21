<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade\Annotate;

/**
 * The control file `psalm-laravel blade:annotate` puts in the child Psalm process's environment,
 * and the channel the run reports back through.
 *
 * This is the whole gate on writing to the user's source tree. The plugin is a Psalm plugin first:
 * an ordinary `vendor/bin/psalm` run has no control file in its environment, so it can never take
 * the annotate path, whatever the project's Blade settings say.
 *
 * @internal
 */
final class AnnotateRequest
{
    public const ENV_VAR = 'PSALM_LARAVEL_BLADE_ANNOTATE';

    private function __construct(
        private readonly string $controlFile,
        public readonly bool $dryRun,
    ) {}

    /** Null — the only safe answer — for anything but a readable control file this CLI wrote. */
    public static function fromEnvironment(): ?self
    {
        $path = \getenv(self::ENV_VAR);

        if (!\is_string($path) || $path === '') {
            return null;
        }

        $raw = @\file_get_contents($path);

        if ($raw === false) {
            return null;
        }

        $decoded = \json_decode($raw, true);

        if (!\is_array($decoded)) {
            return null;
        }

        return new self($path, ($decoded['dryRun'] ?? false) === true);
    }

    /**
     * @param array<string, list<string>> $changed  template path => names declared for it
     * @param array<string, string>       $failures template path => why it was left alone
     */
    public function publish(array $changed, array $failures, string $diff): void
    {
        @\file_put_contents($this->controlFile, (string) \json_encode([
            'dryRun' => $this->dryRun,
            'changed' => $changed,
            'failures' => $failures,
            'diff' => $diff,
        ], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
    }
}
