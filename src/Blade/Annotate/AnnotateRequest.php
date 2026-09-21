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
 * Which makes the environment variable itself the hazard: a stale shell, a `.envrc`, or a CI export
 * left over from one annotate run would otherwise arm every later `psalm` in that environment, and
 * publishing the result would overwrite whatever file the variable happens to name. Hence the
 * marker: only a file this CLI wrote is accepted, and the marker is re-checked before every write,
 * because the path is named by an environment variable and can be repointed mid-run.
 *
 * @internal
 */
final class AnnotateRequest
{
    public const ENV_VAR = 'PSALM_LARAVEL_BLADE_ANNOTATE';

    /** Key that identifies a control file as this CLI's own. */
    public const MARKER = 'psalm-laravel-annotate';

    private function __construct(
        private readonly string $controlFile,
        public readonly bool $dryRun,
    ) {}

    /** Null — the only safe answer — for anything but a marked control file this CLI wrote. */
    public static function fromEnvironment(): ?self
    {
        $path = \getenv(self::ENV_VAR);

        if (!\is_string($path) || $path === '') {
            return null;
        }

        $decoded = self::decode($path);

        if ($decoded === null) {
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
        $this->write([
            'dryRun' => $this->dryRun,
            'changed' => $changed,
            'failures' => $failures,
            'diff' => $diff,
        ]);
    }

    /** Whether the control file is still one this CLI wrote, re-read from disk. */
    public function isValid(): bool
    {
        return self::decode($this->controlFile) !== null;
    }

    /** Why the pass wrote nothing, for the CLI to report as a failure rather than an empty success. */
    public function publishError(string $reason): void
    {
        $this->write(['dryRun' => $this->dryRun, 'error' => $reason]);
    }

    /** @param array<string, mixed> $payload */
    private function write(array $payload): void
    {
        if (self::decode($this->controlFile) === null) {
            return;
        }

        @\file_put_contents(
            $this->controlFile,
            (string) \json_encode([self::MARKER => 1, ...$payload], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES),
        );
    }

    /** @return array<array-key, mixed>|null the control file's contents, null when it is not one */
    private static function decode(string $path): ?array
    {
        // A regular file on disk, never a stream wrapper: `data://text/plain,{...}` would otherwise
        // satisfy the marker with no file at all, arming writes straight from the environment.
        if (\preg_match('#^[a-zA-Z][a-zA-Z0-9+.-]*://#', $path) === 1 || !\is_file($path)) {
            return null;
        }

        $raw = @\file_get_contents($path);

        if ($raw === false) {
            return null;
        }

        $decoded = \json_decode($raw, true);

        if (!\is_array($decoded) || ($decoded[self::MARKER] ?? null) !== 1) {
            return null;
        }

        return $decoded;
    }
}
