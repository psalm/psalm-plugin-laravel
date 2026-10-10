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

    /**
     * Bump when anything the plugin writes around a shadow changes for the same source: the marker
     * pass, the prelude ({@see PreludeBuilder}), suppression injection, or the `$attributes`
     * restore-point re-assert ({@see AttributesRestoreReassert}). It also invalidates a changed
     * manifest-entry layout. The shadow's own compiled bytes are never fingerprinted (see class
     * docblock), so this is the only lever that self-invalidates plugin-side derived facts such as
     * the {@see ViewDataContract} cached in slot 5.
     */
    private const MARKER_PASS_VERSION = 3;

    /** {@see self::isFresh()}: the data-includes slot must have been collected for the entry to count as fresh. */
    public const SLOT_DATA_INCLUDES = 1;

    /**
     * @var array<string, array{0: string, 1: array<int, int>, 2: ?int, 3: string, 4: array<int, list<string>>, 5: array{0: array<string, array{0: string, 1: int, 2: bool}>, 1: bool, 2: list<string>, 3: bool, 4: list<string>, 5: list<string>}, 6: array{0: list<string>, 1: bool}|null}>
     *      shadow path => [template path, lineMap, extendsLine, fingerprint, suppressions, contract,
     *      dataIncludes]. The final slot is null when its collection pass was disabled.
     */
    private array $entries = [];

    /** @var array<string, string> template path => generation selected in this invocation */
    private array $activeGenerations = [];

    private ?string $fingerprintSuffix = null;

    /**
     * @psalm-mutation-free
     */
    public function __construct(
        private readonly string $shadowDir,
        /**
         * {@see CompilerEnvironment::describe()}'s hash of every compiler input besides the
         * template source (custom directives, conditions, component maps, ...). Folded into
         * {@see self::fingerprint()} so an application that edits its own Blade wiring
         * invalidates its cached shadows instead of reusing bytes compiled under a different
         * environment. Empty by default: every existing call site (and every fixture in
         * {@see \Tests\Psalm\LaravelPlugin\Unit\Blade\ShadowManifestTest}) still gets a stable,
         * deterministic fingerprint without naming this parameter.
         */
        private readonly string $environment = '',
    ) {}

    /** Tolerates an absent or corrupt manifest file: starts empty either way. */
    public function load(): void
    {
        $this->activeGenerations = [];
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
     * @return array<string, array{0: string, 1: array<int, int>, 2: ?int, 3: string, 4: array<int, list<string>>, 5: array{0: array<string, array{0: string, 1: int, 2: bool}>, 1: bool, 2: list<string>, 3: bool, 4: list<string>, 5: list<string>}, 6: array{0: list<string>, 1: bool}|null}>
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
            // Arity 7 gates entries written before the removed references slot. Recompiling is
            // safer than misreading a derived cache entry of a different shape.
            if (!\is_string($shadowPath) || !\is_array($entry) || \count($entry) !== 7) {
                continue;
            }

            [$templatePath, $lineMap, $extendsLine, $hash, $suppressions, $contract, $dataIncludes] = \array_values($entry);

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

            $entries[$shadowPath] = [$templatePath, $validLineMap, $extendsLine, $hash, $validSuppressions, $validContract, $validDataIncludes];
        }

        return $entries;
    }

    /**
     * The data-includes slot holds a list of names plus a "something here was not statically
     * resolvable" flag. Null means the collection pass was off when the entry was written; false
     * means the shape is wrong and drops the whole entry.
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
     * An entry written by a plugin version with a different slot count is dropped rather than
     * migrated, which recompiles the template — the cheap, correct answer for a derived cache.
     *
     * @return array{0: array<string, array{0: string, 1: int, 2: bool}>, 1: bool, 2: list<string>, 3: bool, 4: list<string>, 5: list<string>}|null
     *         null when the shape is wrong, which drops the entry
     *
     * @psalm-mutation-free
     */
    private function normalizeContract(mixed $data): ?array
    {
        if (!\is_array($data) || \count($data) !== 6) {
            return null;
        }

        [$vars, $propsUnknown, $readVariables, $readsUnknown, $localVariables, $rawDeclared] = \array_values($data);

        if (!\is_array($vars) || !\is_bool($propsUnknown) || !\is_array($readVariables)
            || !\is_bool($readsUnknown) || !\is_array($localVariables) || !\is_array($rawDeclared)
        ) {
            return null;
        }

        $validReads = $this->normalizeNames($readVariables);
        $validLocals = $this->normalizeNames($localVariables);
        $validRaw = $this->normalizeNames($rawDeclared);

        if ($validReads === null || $validLocals === null || $validRaw === null) {
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

        return [$validVars, $propsUnknown, $validReads, $readsUnknown, $validLocals, $validRaw];
    }

    /**
     * @param array<array-key, mixed> $data
     *
     * @return list<string>|null null when anything in there is not a variable name
     *
     * @psalm-pure
     */
    private function normalizeNames(array $data): ?array
    {
        $names = [];

        /** @psalm-suppress MixedAssignment untyped data straight from an included file */
        foreach ($data as $name) {
            if (!\is_string($name)) {
                return null;
            }

            $names[] = $name;
        }

        return $names;
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
     *        the entry to count as fresh; an entry written with that pass disabled forces a recompile
     *        so the collector actually runs for it.
     */
    public function isFresh(string $templatePath, string $source, int $requiredSlots = 0): bool
    {
        $shadowPath = $this->shadowPath($templatePath, $source);
        $entry = $this->entries[$shadowPath] ?? null;

        if ($entry === null || $entry[3] !== $this->fingerprint($source) || !\is_file($shadowPath)) {
            return false;
        }

        if (($requiredSlots & self::SLOT_DATA_INCLUDES) !== 0 && $entry[6] === null) {
            return false;
        }

        $this->activeGenerations[$templatePath] = $shadowPath;

        return true;
    }

    /**
     * Where a template's shadow lives, whether or not it has been compiled yet. A caller that
     * skipped recompiling a fresh template still has to register the shadow with Psalm.
     */
    public function shadowPathFor(string $templatePath, string $source): string
    {
        return $this->shadowPath($templatePath, $source);
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

        [$vars, $propsUnknown, $readVariables, $readsUnknown, $localVariables, $rawDeclared] = $entry[5];

        $contractVars = [];

        foreach ($vars as $name => [$typeString, $line, $optional]) {
            $contractVars[$name] = new ContractVar($name, $typeString, $line, $optional);
        }

        return new ViewDataContract($contractVars, $propsUnknown, $readVariables, $readsUnknown, $localVariables, $rawDeclared);
    }

    /**
     * Writes the shadow file to disk and records it. Call flush() to persist the manifest itself.
     *
     * @param array{0: list<string>, 1: bool}|null $dataIncludes the subset of references the shadow
     *        hands its whole scope to (`@include`, `@extends`, ...); null when that pass is disabled,
     *        distinct from "collected, found none" and making the read set decline
     */
    public function store(string $templatePath, string $source, ShadowResult $shadow, ViewDataContract $contract, ?array $dataIncludes = null): string
    {
        $shadowPath = $this->shadowPath($templatePath, $source);
        $pid = \getmypid();
        $tmpPath = $shadowPath . '.tmp.' . ($pid !== false ? $pid : 'unknown');

        if (@\file_put_contents($tmpPath, $shadow->contents) === false) {
            // Capture the reason before the cleanup unlink() below, which fails and overwrites it
            // whenever the temp file was never created (only a partial write, e.g. disk full mid-
            // write, actually leaves one behind for that unlink to remove).
            $detail = $this->lastErrorDetail();
            @\unlink($tmpPath);

            throw new \RuntimeException("cannot write shadow file '{$shadowPath}'{$detail}");
        }

        if (!@\rename($tmpPath, $shadowPath)) {
            $detail = $this->lastErrorDetail();
            @\unlink($tmpPath);

            throw new \RuntimeException("cannot write shadow file '{$shadowPath}'{$detail}");
        }

        $vars = [];

        foreach ($contract->vars as $name => $var) {
            $vars[$name] = [$var->typeString, $var->declarationLine, $var->optional];
        }

        $this->activeGenerations[$templatePath] = $shadowPath;
        $this->entries[$shadowPath] = [
            $templatePath,
            $shadow->lineMap,
            $shadow->extendsLine,
            $this->fingerprint($source),
            $shadow->suppressions,
            [$vars, $contract->propsUnknown, $contract->readVariables, $contract->readsUnknown, $contract->localVariables, $contract->rawDeclaredVariables],
            $dataIncludes,
        ];

        return $shadowPath;
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
        return $this->entries[$shadowPath][6] ?? null;
    }

    /**
     * Removes deleted-template shadows and retires superseded live-template entries.
     *
     * @param list<string> $liveTemplatePaths
     */
    public function prune(array $liveTemplatePaths): void
    {
        $live = \array_flip($liveTemplatePaths);

        foreach ($this->entries as $shadowPath => $entry) {
            if (isset($live[$entry[0]])) {
                if (isset($this->activeGenerations[$entry[0]])
                    && $this->activeGenerations[$entry[0]] !== $shadowPath
                ) {
                    // Another invocation can still be analyzing these bytes. Retire metadata
                    // now; reclaim retained generations only when the cache is explicitly cleared.
                    unset($this->entries[$shadowPath]);
                }

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

    private function shadowPath(string $templatePath, string $source): string
    {
        return $this->shadowDir . \DIRECTORY_SEPARATOR . \sha1($templatePath) . '-' . $this->fingerprint($source) . '.php';
    }

    /** The OS-level reason for the most recently suppressed warning, if any, as ": <message>". */
    private function lastErrorDetail(): string
    {
        $error = \error_get_last();

        return $error !== null ? ": {$error['message']}" : '';
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
        // Everything but the source is fixed for the process, and the plugin version costs a
        // Composer lookup, so the suffix is built once rather than per template.
        $this->fingerprintSuffix ??= '|' . self::MARKER_PASS_VERSION . '|' . Application::VERSION . '|'
            . (InstalledVersions::getVersion('psalm/plugin-laravel') ?? 'unknown') . '|' . $this->environment;

        return \hash('xxh128', $source . $this->fingerprintSuffix);
    }
}
