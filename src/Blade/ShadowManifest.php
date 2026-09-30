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

    /** {@see self::isFresh()}: the references slot must have been collected for the entry to count as fresh. */
    public const SLOT_REFERENCES = 1;

    /** {@see self::isFresh()}: likewise for the data-includes slot. */
    public const SLOT_DATA_INCLUDES = 2;

    /**
     * @var array<string, array{0: string, 1: array<int, int>, 2: ?int, 3: string, 4: array<int, list<string>>, 5: array{0: array<string, array{0: string, 1: int, 2: bool}>, 1: bool, 2: list<string>, 3: bool}, 6: array{0: list<string>, 1: bool}|null, 7: array{0: list<string>, 1: bool}|null}>
     *      shadow path => [template path, lineMap, extendsLine, fingerprint, suppressions, contract,
     *      references, dataIncludes]. The last two are null when the entry was written with their
     *      collection pass disabled.
     */
    private array $entries = [];

    /**
     * @psalm-mutation-free
     */
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
     * @return array<string, array{0: string, 1: array<int, int>, 2: ?int, 3: string, 4: array<int, list<string>>, 5: array{0: array<string, array{0: string, 1: int, 2: bool}>, 1: bool, 2: list<string>, 3: bool}, 6: array{0: list<string>, 1: bool}|null, 7: array{0: list<string>, 1: bool}|null}>
     *
     * @psalm-mutation-free
     */
    private function normalizeEntries(mixed $data): array
    {
        if (!\is_array($data)) {
            return [];
        }

        $entries = [];

        foreach ($data as $shadowPath => $entry) {
            // Arity 8 gates every entry written before the data-includes slot was added: one full
            // recompile on upgrade, rather than a manifest carrying entries of two different shapes.
            if (!\is_string($shadowPath) || !\is_array($entry) || \count($entry) !== 8) {
                continue;
            }

            [$templatePath, $lineMap, $extendsLine, $hash, $suppressions, $contract, $references, $dataIncludes] = \array_values($entry);

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

            $validReferences = $this->normalizeViewNames($references);

            if ($validReferences === false) {
                continue;
            }

            $validDataIncludes = $this->normalizeViewNames($dataIncludes);

            if ($validDataIncludes === false) {
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

            $entries[$shadowPath] = [$templatePath, $validLineMap, $extendsLine, $hash, $validSuppressions, $validContract, $validReferences, $validDataIncludes];
        }

        return $entries;
    }

    /**
     * The shape both view-name slots share: a list of names plus a "something here was not
     * statically resolvable" flag. Null is a valid value ("this collection pass was off when the
     * entry was written"), distinct from `false` ("the shape is wrong"), which drops the whole entry.
     *
     * @return array{0: list<string>, 1: bool}|null|false
     *
     * @psalm-pure
     */
    private function normalizeViewNames(mixed $data): array|false|null
    {
        if ($data === null) {
            return null;
        }

        if (!\is_array($data) || \count($data) !== 2) {
            return false;
        }

        [$viewNames, $dynamic] = \array_values($data);

        if (!\is_array($viewNames) || !\is_bool($dynamic)) {
            return false;
        }

        $validNames = [];

        /** @psalm-suppress MixedAssignment untyped data straight from an included file */
        foreach ($viewNames as $viewName) {
            if (!\is_string($viewName)) {
                return false;
            }

            $validNames[] = $viewName;
        }

        return [$validNames, $dynamic];
    }

    /**
     * @return array{0: array<string, array{0: string, 1: int, 2: bool}>, 1: bool, 2: list<string>, 3: bool}|null
     *         null when the shape is wrong, which drops the entry
     *
     * @psalm-pure
     */
    private function normalizeContract(mixed $data): ?array
    {
        if (!\is_array($data) || \count($data) !== 4) {
            return null;
        }

        [$vars, $propsUnknown, $readVariables, $readsUnknown] = \array_values($data);

        if (!\is_array($vars) || !\is_bool($propsUnknown) || !\is_array($readVariables) || !\is_bool($readsUnknown)) {
            return null;
        }

        $validReads = [];

        /** @psalm-suppress MixedAssignment untyped data straight from an included file */
        foreach ($readVariables as $readVariable) {
            if (!\is_string($readVariable)) {
                return null;
            }

            $validReads[] = $readVariable;
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

        return [$validVars, $propsUnknown, $validReads, $readsUnknown];
    }

    /**
     * @return array<int, list<string>>|null null when the shape is wrong, which drops the entry
     *
     * @psalm-pure
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
     *
     * @psalm-mutation-free
     */
    public function shadowEntry(string $shadowPath): ?ShadowEntry
    {
        $entry = $this->entries[$shadowPath] ?? null;

        return $entry === null ? null : new ShadowEntry($entry[0], $entry[1], $entry[4]);
    }

    /**
     * @param int-mask-of<self::SLOT_*> $requiredSlots slots whose collection pass must have run for
     *        the entry to count as fresh; an entry written with that pass disabled (a null slot)
     *        forces a recompile so the collector actually runs for it. Flipping `reportUnusedViews`
     *        or `reportUnusedViewData` on against a cache warmed while it was off must not leave
     *        every template permanently "fresh with nothing ever collected".
     */
    public function isFresh(string $templatePath, string $source, int $requiredSlots = 0): bool
    {
        $shadowPath = $this->shadowPath($templatePath);
        $entry = $this->entries[$shadowPath] ?? null;

        if ($entry === null || $entry[3] !== $this->fingerprint($source) || !\is_file($shadowPath)) {
            return false;
        }

        if (($requiredSlots & self::SLOT_REFERENCES) !== 0 && $entry[6] === null) {
            return false;
        }

        return ($requiredSlots & self::SLOT_DATA_INCLUDES) === 0 || $entry[7] !== null;
    }

    /**
     * Where a template's shadow lives, whether or not it has been compiled yet. A caller that
     * skipped recompiling a fresh template still has to register the shadow with Psalm.
     *
     * @psalm-mutation-free
     */
    public function shadowPathFor(string $templatePath): string
    {
        return $this->shadowPath($templatePath);
    }

    /**
     * The template's declared variables, including for a template that was fresh enough to skip
     * recompiling this run — which is why the contract is persisted rather than re-parsed.
     *
     * @psalm-mutation-free
     */
    public function contractFor(string $shadowPath): ?ViewDataContract
    {
        $entry = $this->entries[$shadowPath] ?? null;

        if ($entry === null) {
            return null;
        }

        [$vars, $propsUnknown, $readVariables, $readsUnknown] = $entry[5];

        $contractVars = [];

        foreach ($vars as $name => [$typeString, $line, $optional]) {
            $contractVars[$name] = new ContractVar($name, $typeString, $line, $optional);
        }

        return new ViewDataContract($contractVars, $propsUnknown, $readVariables, $readsUnknown);
    }

    /**
     * Writes the shadow file to disk and records it. Call flush() to persist the manifest itself.
     *
     * @param array{0: list<string>, 1: bool}|null $references view names the compiled shadow
     *        references, and whether it also holds one this plugin could not resolve statically;
     *        null when reference collection is disabled for this run
     * @param array{0: list<string>, 1: bool}|null $dataIncludes the subset of those the shadow hands
     *        its whole scope to (`@include`, `@extends`, ...); null when that pass is disabled, which
     *        is distinct from "collected, found none" and makes the read set decline
     */
    public function store(string $templatePath, string $source, ShadowResult $shadow, ViewDataContract $contract, ?array $references, ?array $dataIncludes = null): string
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
            [$vars, $contract->propsUnknown, $contract->readVariables, $contract->readsUnknown],
            $references,
            $dataIncludes,
        ];

        return $shadowPath;
    }

    /**
     * The template-side view-name references collected from the compiled shadow, including for a
     * template that was fresh enough to skip recompiling this run. Null for an unknown shadow path
     * or one whose entry was written with reference collection disabled.
     *
     * @return array{0: list<string>, 1: bool}|null
     *
     * @psalm-mutation-free
     */
    public function referencesFor(string $shadowPath): ?array
    {
        return $this->entries[$shadowPath][6] ?? null;
    }

    /**
     * The view names the compiled shadow hands its whole scope to, including for a template that was
     * fresh enough to skip recompiling this run. Null for an unknown shadow path or one whose entry
     * was written with data-include collection disabled.
     *
     * @return array{0: list<string>, 1: bool}|null
     *
     * @psalm-mutation-free
     */
    public function dataIncludesFor(string $shadowPath): ?array
    {
        return $this->entries[$shadowPath][7] ?? null;
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

    /**
     * @psalm-mutation-free
     */
    private function shadowPath(string $templatePath): string
    {
        return $this->shadowDir . \DIRECTORY_SEPARATOR . \sha1($templatePath) . '.php';
    }

    /**
     * @psalm-mutation-free
     */
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
