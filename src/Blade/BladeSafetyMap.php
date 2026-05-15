<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/**
 * Per-template safety record for every Blade view discovered under the
 * configured view roots.
 *
 * The map is built once during plugin boot (similar to how MissingViewHandler
 * loads view paths) and queried for every `view()` / `Factory::make()` call.
 *
 * Entries are keyed by Blade's dotted view name (`emails.welcome`), matching
 * how the view() helper is called in user code. Dotted names are resolved
 * relative to the template roots supplied at construction.
 *
 * Every resolved Blade view is recorded — SAFE, UNSAFE_KEYS, or UNKNOWN.
 * Earlier versions only recorded views with unsafe keys; that conflated "we
 * scanned this view and it is safe" with "we never saw this view", which
 * silently downgrades UNKNOWN to SAFE at the handler layer. Recording all
 * three states is required for sound taint refinement.
 *
 * First-match-wins matches Laravel's {@see \Illuminate\View\FileViewFinder}:
 * the finder iterates view paths in order and returns the first existing
 * `.blade.php`. The map mirrors that, *including* when the first view is SAFE
 * and a later view in the override path is unsafe — earlier versions skipped
 * safe templates, which meant a later UNSAFE view would shadow the SAFE one
 * the finder would actually load.
 *
 * Not marked `@psalm-immutable`: {@see build()} performs filesystem IO, which
 * is inherently impure. Instances returned by `build()` are, however, safe to
 * treat as immutable value objects.
 *
 * @psalm-api
 */
final readonly class BladeSafetyMap
{
    /**
     * @param array<non-empty-string, BladeViewSafety> $safetyByView
     *
     * @psalm-mutation-free
     */
    public function __construct(
        private array $safetyByView,
    ) {}

    /**
     * Build a map by scanning every `*.blade.php` file under the given roots.
     *
     * A single {@see BladeTemplateScanner} is constructed once per build and
     * reused for every template, so the underlying {@see PsalmBladeCompiler}
     * and {@see \PhpParser\Parser} instances are amortised across the scan.
     * Tests that need to substitute a scanner can pass it in.
     *
     * @param list<string>             $viewPaths absolute paths of view directories, in the order
     *                                            returned by FileViewFinder::getPaths()
     * @param BladeTemplateScanner|null $scanner  optional scanner instance; default builds one
     *                                            with {@see BladeTemplateScanner::withDefaults()}.
     *
     * @psalm-api
     */
    public static function build(array $viewPaths, ?BladeTemplateScanner $scanner = null): self
    {
        $scanner ??= BladeTemplateScanner::withDefaults();
        $map = [];

        foreach ($viewPaths as $root) {
            $root = \rtrim($root, \DIRECTORY_SEPARATOR);

            /*
             * Empty-string check must precede is_dir(): is_dir('') emits a
             * "Filename cannot be empty" warning under PHP 8+, which the
             * plugin's error handler turns into a thrown RuntimeException
             * during boot.
             */
            if ($root === '' || !\is_dir($root)) {
                continue;
            }

            foreach (self::iterateBladeFiles($root) as $file) {
                // getPathname() preserves the `$root` prefix the iterator was
                // started with; getRealPath() resolves symlinks (e.g. macOS
                // `/var` -> `/private/var`) and would break prefix-stripping.
                $path = $file->getPathname();

                if ($path === '') {
                    continue;
                }

                $viewName = self::viewNameFor($root, $path);

                if ($viewName === '') {
                    continue;
                }

                // First match wins — matches FileViewFinder::findInPaths(),
                // which iterates paths in order and returns the first match.
                // CRITICAL: this branch must run regardless of the analysis
                // kind; skipping safe views here would let a later-root unsafe
                // shadow take precedence over a first-root safe view that
                // Laravel would actually render.
                if (isset($map[$viewName])) {
                    continue;
                }

                // `@` suppresses the "Permission denied" warning when the
                // file becomes unreadable between the iterator's isFile()
                // check and this call. The `=== false` branch converts the
                // failure to UNKNOWN (FILE_UNREADABLE) so the data is
                // recorded; PR-4+ (handler integration) will surface that
                // state as an explicit Psalm issue or fallback policy
                // decision. Until then there is no user-visible signal
                // for an unreadable view — acceptable because the scanner
                // is not yet wired into analysis output.
                $source = @\file_get_contents($path);

                $analysis = $source === false
                    ? BladeTemplateAnalysis::unknown([BladeUncertaintyReason::FileUnreadable])
                    : $scanner->analyze($source);

                $map[$viewName] = new BladeViewSafety($viewName, $path, $analysis);
            }
        }

        return new self(self::propagateIncludeEdges($map));
    }

    /**
     * Fixed-point pass that consumes every template's `@include` edges and
     * folds child unsafe keys into the parent template's safety record.
     *
     * Two passes:
     *
     *  1. DFS to detect cycle members. A template is a cycle member iff a path
     *     of `@include`-emitted edges starting at it returns to it. Cycle
     *     members are tracked as a set and finalised as
     *     UNKNOWN(IncludeCycle) regardless of any other propagation result.
     *
     *  2. Memoised topological resolution per template:
     *     - Templates whose scanner uncertainty contains only
     *       {@see BladeUncertaintyReason::IncludeResolved} are eligible. For
     *       each include edge, we look up the child's *final* (post-propagation)
     *       state and apply two propagation rules:
     *       * if the parent's explicit data array binds the child's unsafe key
     *         K (i.e. `@include('child', ['K' => $expr])`), every top-level
     *         variable observed in `$expr` is added to the parent's unsafe
     *         keys;
     *       * otherwise, K is added to the parent's unsafe keys verbatim,
     *         because Laravel's `compileInclude()` always forwards the parent
     *         template's scope as the trailing mergeData argument to
     *         `$__env->make()`.
     *     - If any child resolves to UNKNOWN (other than via {@see
     *       BladeUncertaintyReason::IncludeCycle}, which we treat identically),
     *       or if a child's view name is not in the map at all, the parent's
     *       contribution from that include is opaque and the parent stays
     *       UNKNOWN(IncludeDirective). This matches the conservative pre-PR-4
     *       behaviour for the unresolvable case.
     *     - Templates with any uncertainty other than IncludeResolved
     *       (LayoutSectionFlow, IncludeDirective, ComponentTag, UnparsablePhpBlock,
     *       etc., possibly alongside IncludeResolved) are NOT eligible and
     *       pass through unchanged: any other uncertainty already dominates
     *       and propagation would be a no-op anyway.
     *
     * The pass is in-process, runs once per `build()` call, and is bounded by
     * the include-graph size (each template visited at most twice: once in
     * the cycle DFS, once during memoised finalisation).
     *
     * @param array<non-empty-string, BladeViewSafety> $map
     *
     * @return array<non-empty-string, BladeViewSafety>
     */
    private static function propagateIncludeEdges(array $map): array
    {
        $cycleMembers = self::detectIncludeCycles($map);

        /** @var array<string, BladeViewSafety> $finalised */
        $finalised = [];

        foreach (\array_keys($map) as $viewName) {
            $finalised = self::finaliseSafetyForView($viewName, $map, $cycleMembers, $finalised);
        }

        /** @var array<non-empty-string, BladeViewSafety> $result */
        $result = [];

        foreach ($finalised as $viewName => $safety) {
            if ($viewName === '') {
                // Guaranteed unreachable by construction: every writer feeds
                // $finalised from `$map[K] = ...` where K originates from
                // `array_keys($map)` and $map is keyed by `non-empty-string`.
                // The check exists to refine Psalm's array-key type back to
                // `non-empty-string` for the public {@see $safetyByView}
                // contract; without it, the broader `string` key type would
                // bubble up to every caller of the map.
                continue;
            }

            $result[$viewName] = $safety;
        }

        return $result;
    }

    /**
     * DFS over the literal-include graph to record every template that
     * participates in a cycle (template A includes B, B includes A;
     * transitively; or a self-loop).
     *
     * The visit colouring (white → gray → black) is the classic three-colour
     * cycle detection: a back-edge to a gray node identifies a cycle, and the
     * set of stacked nodes from the back-edge target to the top of the stack
     * are exactly the cycle members.
     *
     * @param array<non-empty-string, BladeViewSafety> $map
     *
     * @return array<string, true>
     */
    private static function detectIncludeCycles(array $map): array
    {
        /** @var array<string, 'gray'|'black'> $visit */
        $visit = [];

        /** @var list<string> $stack */
        $stack = [];

        /** @var array<string, true> $cycleMembers */
        $cycleMembers = [];

        foreach (\array_keys($map) as $viewName) {
            if (isset($visit[$viewName])) {
                continue;
            }

            self::dfsForCycles($viewName, $map, $visit, $stack, $cycleMembers);
        }

        return $cycleMembers;
    }

    /**
     * @param array<non-empty-string, BladeViewSafety>      $map
     * @param array<string, 'gray'|'black'>                 $visit
     * @param list<string>                                  $stack
     * @param array<string, true>                           $cycleMembers
     */
    private static function dfsForCycles(
        string $current,
        array $map,
        array &$visit,
        array &$stack,
        array &$cycleMembers,
    ): void {
        $visit[$current] = 'gray';
        $stack[] = $current;

        $safety = $map[$current] ?? null;

        if ($safety instanceof BladeViewSafety && self::isEligibleForPropagation($safety)) {
            foreach ($safety->analysis->includeEdges as $edge) {
                $childName = $edge->childViewName;
                $childState = $visit[$childName] ?? null;

                if ($childState === null) {
                    self::dfsForCycles($childName, $map, $visit, $stack, $cycleMembers);
                } elseif ($childState === 'gray') {
                    // Back-edge: every node from the gray child up to the
                    // current top of the stack participates in the cycle.
                    $cycleStart = \array_search($childName, $stack, true);

                    if ($cycleStart !== false) {
                        $stackSize = \count($stack);

                        for ($i = $cycleStart; $i < $stackSize; ++$i) {
                            $cycleMembers[$stack[$i]] = true;
                        }
                    }
                }
            }
        }

        \array_pop($stack);
        $visit[$current] = 'black';
    }

    /**
     * Compute (memoised) the post-propagation safety record for `$viewName`
     * and return an updated `$finalised` map. Non-eligible templates pass
     * through unchanged; cycle members become UNKNOWN(IncludeCycle); eligible
     * templates fold each child include's contribution per the rules
     * documented on {@see propagateIncludeEdges()}.
     *
     * Returns the new accumulator rather than mutating by reference: Psalm's
     * by-reference param type tracking widens the array key type to plain
     * `string` once any non-`array_keys` source contributes, which would
     * break the narrow `non-empty-string` invariant of {@see $safetyByView}.
     * Functional accumulator passes the inferred type back to the caller.
     *
     * @param array<non-empty-string, BladeViewSafety> $map
     * @param array<string, true>                      $cycleMembers
     * @param array<string, BladeViewSafety>           $finalised
     *
     * @return array<string, BladeViewSafety>
     *
     * @psalm-pure
     */
    private static function finaliseSafetyForView(
        string $viewName,
        array $map,
        array $cycleMembers,
        array $finalised,
    ): array {
        if (isset($finalised[$viewName])) {
            return $finalised;
        }

        $safety = $map[$viewName] ?? null;

        if (!$safety instanceof BladeViewSafety) {
            return $finalised;
        }

        if (isset($cycleMembers[$viewName])) {
            $newAnalysis = BladeTemplateAnalysis::unknown(
                [BladeUncertaintyReason::IncludeCycle],
                $safety->analysis->unsafeKeys,
            );

            $finalised[$viewName] = new BladeViewSafety($safety->viewName, $safety->path, $newAnalysis);

            return $finalised;
        }

        if (!self::isEligibleForPropagation($safety)) {
            // Template has uncertainties other than IncludeResolved (e.g.
            // ComponentTag + IncludeResolved). The non-IncludeResolved
            // uncertainty dominates; propagation would be a no-op anyway.
            // Strip IncludeResolved before exposing the safety record: the
            // enum's documented contract is that consumers of a built
            // BladeSafetyMap never see IncludeResolved. Without the strip,
            // mixed-uncertainty templates would leak the intermediate marker.
            $finalised[$viewName] = self::stripIncludeResolved($safety);

            return $finalised;
        }

        $combined = \array_fill_keys($safety->analysis->unsafeKeys, true);

        $hasUnknownContribution = false;

        foreach ($safety->analysis->includeEdges as $edge) {
            $childName = $edge->childViewName;

            if (!isset($map[$childName])) {
                // Include target not in the scanned roots: typo, package view,
                // or out-of-scope path. Conservative fallback — the include's
                // contribution is opaque.
                $hasUnknownContribution = true;
                continue;
            }

            $finalised = self::finaliseSafetyForView($childName, $map, $cycleMembers, $finalised);

            $childSafety = $finalised[$childName] ?? null;

            if (!$childSafety instanceof BladeViewSafety) {
                $hasUnknownContribution = true;
                continue;
            }

            if ($childSafety->analysis->kind === BladeViewSafetyKind::Unknown) {
                $hasUnknownContribution = true;
                continue;
            }

            foreach ($childSafety->analysis->unsafeKeys as $childKey) {
                $combined = self::propagateChildKey($childKey, $edge->explicitKeyMap, $combined);
            }
        }

        if ($hasUnknownContribution) {
            $newAnalysis = BladeTemplateAnalysis::unknown(
                [BladeUncertaintyReason::IncludeDirective],
                $safety->analysis->unsafeKeys,
            );
        } else {
            $keys = \array_keys($combined);

            $newAnalysis = $keys === []
                ? BladeTemplateAnalysis::safe()
                : BladeTemplateAnalysis::unsafeKeys($keys);
        }

        $finalised[$viewName] = new BladeViewSafety($safety->viewName, $safety->path, $newAnalysis);

        return $finalised;
    }

    /**
     * Apply the per-key propagation rule for one `(child unsafe key, parent
     * explicit data array)` pair. Returns the new accumulator with the parent
     * variables contributed by this child key folded in.
     *
     * Functional shape (return-the-accumulator rather than mutate-by-ref) so
     * Psalm can preserve the `non-empty-string` key invariant across calls.
     *
     * @param array<non-empty-string, list<non-empty-string>>|null $explicitKeyMap
     * @param array<non-empty-string, true>                        $combined
     *
     * @return array<non-empty-string, true>
     *
     * @psalm-pure
     */
    private static function propagateChildKey(
        string $childKey,
        ?array $explicitKeyMap,
        array $combined,
    ): array {
        if ($explicitKeyMap !== null && isset($explicitKeyMap[$childKey])) {
            // Explicit binding wins. Parent's explicit array maps the child's
            // key to some expression; the parent variables present in that
            // expression are the parent's contribution.
            foreach ($explicitKeyMap[$childKey] as $parentVar) {
                $combined[$parentVar] = true;
            }

            return $combined;
        }

        // mergeData verbatim pass-through. `compileInclude()` always forwards
        // the parent template's whole scope, so a child unsafe key K that the
        // parent does not bind explicitly reaches the child as the parent's
        // `$K`. Parent unsafe keys gain K verbatim.
        if ($childKey !== '') {
            $combined[$childKey] = true;
        }

        return $combined;
    }

    /**
     * Project a non-eligible safety record onto its post-propagation form by
     * removing the intermediate {@see BladeUncertaintyReason::IncludeResolved}
     * marker. The remaining uncertainty list (LayoutSectionFlow, ComponentTag,
     * IncludeDirective, etc.) already dominates the result, and the enum
     * documents that IncludeResolved is never exposed to map consumers.
     *
     * Returns the original safety unchanged when no IncludeResolved marker is
     * present — the common case for templates whose only uncertainty is
     * something other than an include directive.
     *
     * @psalm-pure
     */
    private static function stripIncludeResolved(BladeViewSafety $safety): BladeViewSafety
    {
        $uncertainties = $safety->analysis->uncertainties;
        $filtered = [];
        $sawIncludeResolved = false;

        foreach ($uncertainties as $reason) {
            if ($reason === BladeUncertaintyReason::IncludeResolved) {
                $sawIncludeResolved = true;
                continue;
            }

            $filtered[] = $reason;
        }

        if (!$sawIncludeResolved) {
            return $safety;
        }

        if ($filtered === []) {
            // Defensive: a template flagged non-eligible MUST have at least
            // one non-IncludeResolved uncertainty (that is what makes it
            // non-eligible). Reaching here with $filtered === [] means the
            // eligibility check and this filter disagreed; restoring the
            // original record is the safest fallback (still wrong, but does
            // not crash via the unknown() non-empty contract).
            return $safety;
        }

        $newAnalysis = BladeTemplateAnalysis::unknown($filtered, $safety->analysis->unsafeKeys);

        return new BladeViewSafety($safety->viewName, $safety->path, $newAnalysis);
    }

    /**
     * True if a template is a candidate for include-edge propagation: its
     * only uncertainty is {@see BladeUncertaintyReason::IncludeResolved}, and
     * therefore the propagation pass can soundly fold child contributions
     * back into a SAFE / UNSAFE_KEYS result.
     *
     * Templates with any other uncertainty (LayoutSectionFlow, IncludeDirective,
     * ComponentTag, etc.) stay UNKNOWN regardless, so propagation would be a
     * no-op anyway.
     *
     * @psalm-pure
     */
    private static function isEligibleForPropagation(BladeViewSafety $safety): bool
    {
        $uncertainties = $safety->analysis->uncertainties;

        if ($uncertainties === []) {
            return false;
        }

        foreach ($uncertainties as $uncertainty) {
            if ($uncertainty !== BladeUncertaintyReason::IncludeResolved) {
                return false;
            }
        }

        return true;
    }

    /**
     * The full safety record for a view, or null when the view is unknown to
     * the map (e.g. dynamic include, package view we did not scan, or a typo).
     * Callers needing to distinguish "scanned and safe" from "never seen"
     * must use this, not {@see unsafeKeysFor()}.
     *
     * @psalm-api
     * @psalm-mutation-free
     */
    public function safetyFor(string $viewName): ?BladeViewSafety
    {
        return $this->safetyByView[$viewName] ?? null;
    }

    /**
     * Convenience for handlers that only need the unsafe-key list. Returns
     * the empty list both for SAFE views and for views the map never saw, so
     * it must NOT be used to decide whether to apply the UNKNOWN fallback —
     * use {@see isUnknown()} or {@see safetyFor()} for that decision.
     *
     * @return list<non-empty-string>
     *
     * @psalm-api
     * @psalm-mutation-free
     */
    public function unsafeKeysFor(string $viewName): array
    {
        return ($this->safetyByView[$viewName] ?? null)?->unsafeKeys() ?? [];
    }

    /**
     * @psalm-api
     * @psalm-mutation-free
     */
    public function isKnownSafe(string $viewName): bool
    {
        return ($this->safetyByView[$viewName] ?? null)?->kind() === BladeViewSafetyKind::Safe;
    }

    /**
     * @psalm-api
     * @psalm-mutation-free
     */
    public function isUnknown(string $viewName): bool
    {
        return ($this->safetyByView[$viewName] ?? null)?->kind() === BladeViewSafetyKind::Unknown;
    }

    /**
     * Every view name the map recorded, in insertion order (first-root first).
     *
     * @return list<non-empty-string>
     *
     * @psalm-api
     * @psalm-mutation-free
     */
    public function knownViews(): array
    {
        return \array_keys($this->safetyByView);
    }

    /**
     * @return iterable<\SplFileInfo>
     */
    private static function iterateBladeFiles(string $root): iterable
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \RecursiveDirectoryIterator::SKIP_DOTS),
        );

        /** @var \SplFileInfo $file */
        foreach ($iterator as $file) {
            if (!$file->isFile()) {
                continue;
            }

            // Match Laravel's FileViewFinder default extension. str_ends_with
            // (not str_contains) so editor temp files like `foo.blade.php.bak`
            // or `foo.blade.php~` don't leak into the scan.
            if (!\str_ends_with($file->getFilename(), '.blade.php')) {
                continue;
            }

            yield $file;
        }
    }

    /**
     * Convert an absolute template path into Blade's dotted view name, mirroring
     * FileViewFinder::getPossibleViewFiles() in reverse.
     *
     * Example: root=/app/resources/views, path=/app/resources/views/emails/welcome.blade.php
     *          => "emails.welcome"
     *
     * @psalm-pure
     */
    private static function viewNameFor(string $root, string $path): string
    {
        $relative = \substr($path, \strlen($root) + 1);

        // Blade templates are always `.blade.php`; strip the suffix to get
        // the dotted view name. Non-blade files are filtered out earlier.
        if (\str_ends_with($relative, '.blade.php')) {
            $relative = \substr($relative, 0, -\strlen('.blade.php'));
        }

        return \str_replace(\DIRECTORY_SEPARATOR, '.', $relative);
    }
}
