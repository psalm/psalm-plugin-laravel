<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

use Composer\InstalledVersions;
use Illuminate\Foundation\Application;

/**
 * Tracks compiled shadow files on disk against a fingerprint of the Blade
 * source that produced them, so an unchanged template can skip recompiling.
 *
 * Never fingerprints compiled output: directives such as `@once` mint a
 * fresh UUID on every compile, so two compiles of the SAME source are never
 * byte-identical — only the source and the compiler inputs are stable.
 */
final class ShadowManifest
{
    private const MANIFEST_FILE = 'manifest.php';

    /** Bump when MarkerPrePass changes in a way that changes shadow output for the same source. */
    private const MARKER_PASS_VERSION = 1;

    /**
     * @var array<string, array{0: string, 1: array<int, int>, 2: ?int, 3: string, 4: array<int, list<string>>, 5: array{0: array<string, array{0: string, 1: int, 2: bool}>, 1: bool}}>
     *      shadow path => [template path, lineMap, extendsLine, fingerprint, suppressions, contract]
     */
    private array $entries = [];

    public function __construct(private readonly string $shadowDir) {}

    /** Tolerates an absent or corrupt manifest file: starts empty either way. */
    public function load(): void
    {
        $path = $this->manifestPath();

        if (!\is_file($path)) {
            $this->entries = [];

            return;
        }

        try {
            $this->entries = $this->normalizeEntries(@include $path);
        } catch (\Throwable) {
            $this->entries = [];
        }
    }

    /**
     * Validates the shape of whatever `include` handed back — a var_export'd
     * array from a version of this same class, or arbitrary garbage if the
     * file was corrupted mid-write. Individually malformed entries are
     * dropped rather than failing the whole load.
     *
     * @return array<string, array{0: string, 1: array<int, int>, 2: ?int, 3: string, 4: array<int, list<string>>, 5: array{0: array<string, array{0: string, 1: int, 2: bool}>, 1: bool}}>
     */
    private function normalizeEntries(mixed $data): array
    {
        if (!\is_array($data)) {
            return [];
        }

        $entries = [];

        foreach ($data as $shadowPath => $entry) {
            if (!\is_string($shadowPath) || !\is_array($entry) || \count($entry) !== 6) {
                continue;
            }

            [$templatePath, $lineMap, $extendsLine, $hash, $suppressions, $contract] = \array_values($entry);

            if (!\is_string($templatePath) || !\is_array($lineMap) || !\is_string($hash)) {
                continue;
            }

            if ($extendsLine !== null && !\is_int($extendsLine)) {
                continue;
            }

            $validSuppressions = $this->normalizeSuppressions($suppressions);

            if ($validSuppressions === null) {
                continue;
            }

            $validContract = $this->normalizeContract($contract);

            if ($validContract === null) {
                continue;
            }

            $validLineMap = [];

            /** @psalm-suppress MixedAssignment untyped data straight from an included file */
            foreach ($lineMap as $shadowLine => $bladeLine) {
                if (!\is_int($shadowLine) || !\is_int($bladeLine)) {
                    continue 2;
                }

                $validLineMap[$shadowLine] = $bladeLine;
            }

            $entries[$shadowPath] = [$templatePath, $validLineMap, $extendsLine, $hash, $validSuppressions, $validContract];
        }

        return $entries;
    }

    /**
     * @return array{0: array<string, array{0: string, 1: int, 2: bool}>, 1: bool}|null null when the
     *         shape is wrong, which drops the entry
     */
    private function normalizeContract(mixed $data): ?array
    {
        if (!\is_array($data) || \count($data) !== 2) {
            return null;
        }

        [$vars, $propsUnknown] = \array_values($data);

        if (!\is_array($vars) || !\is_bool($propsUnknown)) {
            return null;
        }

        $validVars = [];

        /** @psalm-suppress MixedAssignment untyped data straight from an included file */
        foreach ($vars as $name => $var) {
            if (!\is_string($name) || !\is_array($var) || \count($var) !== 3) {
                return null;
            }

            [$typeString, $line, $optional] = \array_values($var);

            if (!\is_string($typeString) || !\is_int($line) || !\is_bool($optional)) {
                return null;
            }

            $validVars[$name] = [$typeString, $line, $optional];
        }

        return [$validVars, $propsUnknown];
    }

    /**
     * @return array<int, list<string>>|null null when the shape is wrong, which drops the entry
     */
    private function normalizeSuppressions(mixed $data): ?array
    {
        if (!\is_array($data)) {
            return null;
        }

        $suppressions = [];

        /** @psalm-suppress MixedAssignment untyped data straight from an included file */
        foreach ($data as $bladeLine => $rules) {
            if (!\is_int($bladeLine) || !\is_array($rules)) {
                return null;
            }

            $validRules = [];

            /** @psalm-suppress MixedAssignment untyped data straight from an included file */
            foreach ($rules as $rule) {
                if (!\is_string($rule)) {
                    return null;
                }

                $validRules[] = $rule;
            }

            $suppressions[$bladeLine] = $validRules;
        }

        return $suppressions;
    }

    /**
     * The facts the issue remap needs about a shadow, including for a template that was fresh
     * enough to skip recompiling this run.
     */
    public function shadowEntry(string $shadowPath): ?ShadowEntry
    {
        $entry = $this->entries[$shadowPath] ?? null;

        return $entry === null ? null : new ShadowEntry($entry[0], $entry[1], $entry[4]);
    }

    public function isFresh(string $templatePath, string $source): bool
    {
        $shadowPath = $this->shadowPath($templatePath);
        $entry = $this->entries[$shadowPath] ?? null;

        return $entry !== null
            && $entry[3] === $this->fingerprint($source)
            && \is_file($shadowPath);
    }

    /**
     * Where a template's shadow lives, whether or not it has been compiled yet. A caller that
     * skipped recompiling a fresh template still has to register the shadow with Psalm.
     */
    public function shadowPathFor(string $templatePath): string
    {
        return $this->shadowPath($templatePath);
    }

    /**
     * The template's declared variables, including for a template that was fresh enough to skip
     * recompiling this run — which is why the contract is persisted rather than re-parsed.
     */
    public function contractFor(string $shadowPath): ?ViewDataContract
    {
        $entry = $this->entries[$shadowPath] ?? null;

        if ($entry === null) {
            return null;
        }

        [$vars, $propsUnknown] = $entry[5];

        $contractVars = [];

        foreach ($vars as $name => [$typeString, $line, $optional]) {
            $contractVars[$name] = new ContractVar($name, $typeString, $line, $optional);
        }

        return new ViewDataContract($contractVars, $propsUnknown);
    }

    /** Writes the shadow file to disk and records it. Call flush() to persist the manifest itself. */
    public function store(string $templatePath, string $source, ShadowResult $shadow, ViewDataContract $contract): string
    {
        $shadowPath = $this->shadowPath($templatePath);

        if (@\file_put_contents($shadowPath, $shadow->contents) === false) {
            throw new \RuntimeException("cannot write shadow file '{$shadowPath}'");
        }

        $vars = [];

        foreach ($contract->vars as $name => $var) {
            $vars[$name] = [$var->typeString, $var->declarationLine, $var->optional];
        }

        $this->entries[$shadowPath] = [
            $templatePath,
            $shadow->lineMap,
            $shadow->extendsLine,
            $this->fingerprint($source),
            $shadow->suppressions,
            [$vars, $contract->propsUnknown],
        ];

        return $shadowPath;
    }

    /**
     * Removes shadow files (and their entries) for templates that no longer exist.
     *
     * @param list<string> $liveTemplatePaths
     */
    public function prune(array $liveTemplatePaths): void
    {
        $live = \array_flip($liveTemplatePaths);

        foreach ($this->entries as $shadowPath => $entry) {
            if (isset($live[$entry[0]])) {
                continue;
            }

            @\unlink($shadowPath);
            unset($this->entries[$shadowPath]);
        }
    }

    /** Atomic write: temp file + rename, so a crash mid-write never corrupts the manifest. */
    public function flush(): void
    {
        $path = $this->manifestPath();
        $pid = \getmypid();
        $tmpPath = $path . '.tmp.' . ($pid !== false ? $pid : 'unknown');

        if (@\file_put_contents($tmpPath, "<?php\n\nreturn " . \var_export($this->entries, true) . ";\n") === false) {
            return;
        }

        if (!@\rename($tmpPath, $path)) {
            @\unlink($tmpPath);
        }
    }

    private function shadowPath(string $templatePath): string
    {
        return $this->shadowDir . \DIRECTORY_SEPARATOR . \sha1($templatePath) . '.php';
    }

    private function manifestPath(): string
    {
        return $this->shadowDir . \DIRECTORY_SEPARATOR . self::MANIFEST_FILE;
    }

    private function fingerprint(string $source): string
    {
        $pluginVersion = InstalledVersions::getVersion('psalm/plugin-laravel') ?? 'unknown';

        return \hash('xxh128', $source . '|' . self::MARKER_PASS_VERSION . '|' . Application::VERSION . '|' . $pluginVersion);
    }
}
