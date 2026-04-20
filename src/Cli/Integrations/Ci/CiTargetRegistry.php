<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Cli\Integrations\Ci;

/**
 * Resolves a user-supplied target name to a concrete CiTargetInterface.
 *
 * The registry is the single seam where new CI providers get wired up: a
 * future GitLab or Bitbucket adapter only needs to be added to
 * {@see self::default()} for both the explicit name (e.g. `add gitlab`) and
 * the auto-detecting `ci` alias to pick it up.
 *
 * The `ci` alias is intentionally not a registered id — it runs detection
 * against every registered adapter and falls back to the first one (currently
 * GitHub) when nothing in the project hints at a provider.
 */
final class CiTargetRegistry
{
    /** @var non-empty-array<string, CiTargetInterface> */
    private readonly array $targetsById;

    /**
     * @param non-empty-list<CiTargetInterface> $targets at least one target must
     *                                                  be registered so the `ci`
     *                                                  fallback always resolves
     *
     * @psalm-mutation-free
     */
    public function __construct(array $targets)
    {
        $byId = [];
        foreach ($targets as $target) {
            $id = $target->id();
            if (isset($byId[$id])) {
                // Silently overwriting would mask the misconfiguration and
                // make auto-detection order depend on registration sequence
                // in subtle ways. Fail loudly with both implementation names
                // so the caller can see which adapter they need to drop.
                throw new \RuntimeException(\sprintf(
                    'Duplicate CI target id "%s" registered by %s and %s.',
                    $id,
                    $byId[$id]::class,
                    $target::class,
                ));
            }
            $byId[$id] = $target;
        }

        if ($byId === []) {
            // PHP type system can't enforce non-empty-list on runtime input, so
            // a defensive check surfaces misconfiguration clearly rather than
            // letting `ci` fall through to an undefined array key later.
            throw new \RuntimeException('CiTargetRegistry requires at least one target.');
        }

        if (isset($byId['ci'])) {
            // `ci` is reserved for the auto-detect alias in resolve(); an
            // adapter advertising that id would be silently unreachable.
            throw new \RuntimeException('Target id "ci" is reserved for the auto-detect alias.');
        }

        $this->targetsById = $byId;
    }

    /**
     * Default registry wired for production use. New CI adapters are registered
     * here; all other code (AddCommand, the bin script) routes through this
     * method and automatically picks them up.
     *
     * @psalm-pure
     */
    public static function default(): self
    {
        return new self([
            new GitHubActionsTarget(),
        ]);
    }

    /**
     * @return list<string> all canonical target ids (excluding the `ci` alias)
     *
     * @psalm-mutation-free
     */
    public function ids(): array
    {
        return \array_keys($this->targetsById);
    }

    /**
     * Resolve a user-supplied name to a target, including the `ci` alias.
     *
     * Marked impure because the `ci` branch delegates to {@see self::autoDetect()},
     * which inspects the filesystem through {@see CiTargetInterface::detect()}.
     *
     * @throws UnknownCiTargetException if $name is neither `ci` nor a registered id
     *
     * @psalm-impure
     */
    public function resolve(string $name, string $projectRoot): CiTargetInterface
    {
        if ($name === 'ci') {
            return $this->autoDetect($projectRoot);
        }

        return $this->targetsById[$name]
            ?? throw new UnknownCiTargetException($name, $this->ids());
    }

    /**
     * Picks the first registered target whose detect() returns true for the
     * project. When nothing matches, falls back to the first registered target
     * (GitHub in the default registry). A deterministic fallback is better than
     * an error because most users running `add ci` on a project with no CI
     * config yet still want GitHub Actions written.
     *
     * @psalm-impure
     */
    private function autoDetect(string $projectRoot): CiTargetInterface
    {
        foreach ($this->targetsById as $target) {
            if ($target->detect($projectRoot)) {
                return $target;
            }
        }

        // Guaranteed non-empty by the constructor invariant. We use array_values()
        // rather than reset() because the latter mutates the array's internal
        // pointer, which PHP rejects on readonly properties.
        return \array_values($this->targetsById)[0];
    }
}
