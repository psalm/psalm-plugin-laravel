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

        return new self(self::propagateEdges($map));
    }

    /**
     * Fixed-point pass that consumes every template's `@include` and
     * resolvable `<x-foo ... />` component edges and folds child unsafe keys
     * into the parent template's safety record.
     *
     * Two passes:
     *
     *  1. Unified DFS over the combined include + component edge graph to
     *     detect cycle members. A template is a cycle member iff a path of
     *     edges (of either type) starting at it returns to it. Cycle members
     *     are finalised as UNKNOWN(IncludeCycle) regardless of any other
     *     propagation result. Including component edges in the cycle graph
     *     is mandatory for soundness: `A` includes `B`, `B` includes
     *     `<x-A />` is a real cycle that pure include-only detection would
     *     miss.
     *
     *  2. Memoised topological resolution per template:
     *     - Templates whose scanner uncertainty is a subset of
     *       `{IncludeResolved, ComponentResolved}` are eligible. For each
     *       edge, we look up the child's *final* (post-propagation) state
     *       and apply the edge-kind-specific rule:
     *       * Include edges (mergeData pass-through): if the parent's
     *         explicit data array binds the child's unsafe key K, every
     *         top-level parent variable observed in the bound expression is
     *         added to the parent's unsafe keys; otherwise K is added
     *         verbatim because Laravel's `compileInclude()` forwards the
     *         parent template's whole scope as `$mergeData`.
     *       * Component edges (no mergeData pass-through): a child unsafe
     *         key K contributes to the parent only when K appears in the
     *         edge's explicit attribute map (camelized). Anonymous
     *         components do not inherit parent scope, so any child unsafe
     *         key the parent did not bind by name is dropped.
     *     - If a component edge has no candidate view name matching a
     *       scanned template, or if any child resolves to UNKNOWN (other
     *       than via {@see BladeUncertaintyReason::IncludeCycle}, which we
     *       treat identically), the parent's contribution from that edge
     *       is opaque. The parent surfaces UNKNOWN with
     *       {@see BladeUncertaintyReason::IncludeDirective} when the opaque
     *       contribution came from an include, or
     *       {@see BladeUncertaintyReason::ComponentTag} when it came from a
     *       component. Both can be present.
     *     - Templates with any uncertainty outside the
     *       `{IncludeResolved, ComponentResolved}` set
     *       (LayoutSectionFlow, IncludeDirective, ComponentTag,
     *       UnparsablePhpBlock, etc., possibly alongside the intermediate
     *       markers) are NOT eligible and pass through unchanged: any other
     *       uncertainty already dominates.
     *
     * The pass is in-process, runs once per `build()` call, and is bounded by
     * the combined edge-graph size.
     *
     * @param array<non-empty-string, BladeViewSafety> $map
     *
     * @return array<non-empty-string, BladeViewSafety>
     */
    private static function propagateEdges(array $map): array
    {
        $cycleMembers = self::detectEdgeCycles($map);

        /** @var array<string, BladeViewSafety> $finalised */
        $finalised = [];

        foreach (\array_keys($map) as $viewName) {
            self::finaliseSafetyForView($viewName, $map, $cycleMembers, $finalised);
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
     * Unified DFS over the combined include + resolvable-component edge
     * graph to record every template that participates in a cycle (template
     * A reaches itself through any path of `@include` edges and / or
     * resolved `<x-foo ... />` edges; transitively; or a self-loop).
     *
     * The visit colouring (white → gray → black) is the classic three-colour
     * cycle detection: a back-edge to a gray node identifies a cycle, and the
     * set of stacked nodes from the back-edge target to the top of the stack
     * are exactly the cycle members. Walking both edge types in one graph
     * is required for soundness: a cycle that crosses edge types
     * (`parent.blade.php` does `@include('child')` while `child.blade.php`
     * does `<x-parent />`) is a real cycle that two separate DFS passes
     * would miss.
     *
     * @param array<non-empty-string, BladeViewSafety> $map
     *
     * @return array<string, true>
     */
    private static function detectEdgeCycles(array $map): array
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
                self::dfsVisitChild($edge->childViewName, $map, $visit, $stack, $cycleMembers);
            }

            foreach ($safety->analysis->componentEdges as $componentEdge) {
                $resolved = self::resolveComponentChild($componentEdge, $map);

                if ($resolved === null) {
                    continue;
                }

                self::dfsVisitChild($resolved, $map, $visit, $stack, $cycleMembers);
            }
        }

        \array_pop($stack);
        $visit[$current] = 'black';
    }

    /**
     * Shared cycle-detection step: visit a child template node from inside
     * {@see dfsForCycles()}. Extracted so include and component edges share
     * one back-edge / recursion handler.
     *
     * @param array<non-empty-string, BladeViewSafety> $map
     * @param array<string, 'gray'|'black'>            $visit
     * @param list<string>                             $stack
     * @param array<string, true>                      $cycleMembers
     */
    private static function dfsVisitChild(
        string $childName,
        array $map,
        array &$visit,
        array &$stack,
        array &$cycleMembers,
    ): void {
        $childState = $visit[$childName] ?? null;

        if ($childState === null) {
            self::dfsForCycles($childName, $map, $visit, $stack, $cycleMembers);

            return;
        }

        if ($childState === 'gray') {
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

    /**
     * Pick the first candidate view name the edge lists that is present in
     * the scanned-templates map. Mirrors Laravel's
     * `ComponentTagCompiler::guessAnonymousComponentUsingPaths()` first-match
     * rule.
     *
     * @param array<non-empty-string, BladeViewSafety> $map
     *
     * @psalm-pure
     */
    private static function resolveComponentChild(BladeComponentEdge $edge, array $map): ?string
    {
        foreach ($edge->candidateViewNames as $candidate) {
            if (isset($map[$candidate])) {
                return $candidate;
            }
        }

        return null;
    }

    /**
     * Compute (memoised) the post-propagation safety record for `$viewName`
     * and mutate `$finalised` in place. Non-eligible templates pass through
     * unchanged; cycle members become UNKNOWN(IncludeCycle); eligible
     * templates fold each child include's contribution per the rules
     * documented on {@see propagateEdges()}.
     *
     * Mutates by reference rather than returning the accumulator: the public
     * seam ({@see propagateEdges()}) already widens the key type to plain
     * `string` and re-narrows on the way out, so the internal recursion has
     * no remaining narrowing to preserve. Returning a fresh accumulator from
     * every recursive call forced PHP to copy-on-write the array's bucket
     * table per descent, which was O(depth * accumulator_size) hashtable
     * allocations on real apps. Switching to by-reference keeps the same
     * memoisation logic with O(1) per-descent overhead.
     *
     * @param array<non-empty-string, BladeViewSafety> $map
     * @param array<string, true>                      $cycleMembers
     * @param array<string, BladeViewSafety>           $finalised
     */
    private static function finaliseSafetyForView(
        string $viewName,
        array $map,
        array $cycleMembers,
        array &$finalised,
    ): void {
        if (isset($finalised[$viewName])) {
            return;
        }

        $safety = $map[$viewName] ?? null;

        if (!$safety instanceof BladeViewSafety) {
            return;
        }

        if (isset($cycleMembers[$viewName])) {
            $newAnalysis = BladeTemplateAnalysis::unknown(
                [BladeUncertaintyReason::IncludeCycle],
                $safety->analysis->unsafeKeys,
            );

            $finalised[$viewName] = new BladeViewSafety($safety->viewName, $safety->path, $newAnalysis);

            return;
        }

        if (!self::isEligibleForPropagation($safety)) {
            // Template has uncertainties outside the
            // {IncludeResolved, ComponentResolved} eligibility set (e.g.
            // a LayoutSectionFlow or a real IncludeDirective alongside one
            // of the intermediate markers). The non-intermediate
            // uncertainty dominates; propagation would be a no-op anyway.
            // Strip the intermediate markers before exposing the safety
            // record: the enum's documented contract is that consumers of
            // a built BladeSafetyMap never see IncludeResolved or
            // ComponentResolved.
            $finalised[$viewName] = self::stripIntermediateMarkers($safety);

            return;
        }

        $combined = \array_fill_keys($safety->analysis->unsafeKeys, true);

        $hasIncludeOpaqueContribution = false;
        $hasComponentOpaqueContribution = false;

        foreach ($safety->analysis->includeEdges as $edge) {
            $childName = $edge->childViewName;

            if (!isset($map[$childName])) {
                // Include target not in the scanned roots: typo, package view,
                // or out-of-scope path. Conservative fallback — the include's
                // contribution is opaque.
                $hasIncludeOpaqueContribution = true;
                continue;
            }

            self::finaliseSafetyForView($childName, $map, $cycleMembers, $finalised);

            $childSafety = $finalised[$childName] ?? null;

            if (!$childSafety instanceof BladeViewSafety) {
                $hasIncludeOpaqueContribution = true;
                continue;
            }

            if ($childSafety->analysis->kind === BladeViewSafetyKind::Unknown) {
                $hasIncludeOpaqueContribution = true;
                continue;
            }

            foreach ($childSafety->analysis->unsafeKeys as $childKey) {
                $combined = self::propagateChildKey($childKey, $edge->explicitKeyMap, $combined);
            }
        }

        foreach ($safety->analysis->componentEdges as $componentEdge) {
            $resolvedChild = self::resolveComponentChild($componentEdge, $map);

            if ($resolvedChild === null) {
                // No anonymous candidate exists in the scanned roots. The
                // component is likely a class component, a namespaced
                // anonymous component the v1 scanner does not model, or a
                // typo. Conservative fallback — the component's
                // contribution is opaque.
                $hasComponentOpaqueContribution = true;
                continue;
            }

            self::finaliseSafetyForView($resolvedChild, $map, $cycleMembers, $finalised);

            $childSafety = $finalised[$resolvedChild] ?? null;

            if (!$childSafety instanceof BladeViewSafety) {
                $hasComponentOpaqueContribution = true;
                continue;
            }

            if ($childSafety->analysis->kind === BladeViewSafetyKind::Unknown) {
                $hasComponentOpaqueContribution = true;
                continue;
            }

            foreach ($childSafety->analysis->unsafeKeys as $childKey) {
                $combined = self::propagateComponentChildKey($childKey, $componentEdge->explicitKeyMap, $combined);
            }
        }

        if ($hasIncludeOpaqueContribution || $hasComponentOpaqueContribution) {
            /** @var non-empty-list<BladeUncertaintyReason> $reasons */
            $reasons = [];

            if ($hasIncludeOpaqueContribution) {
                $reasons[] = BladeUncertaintyReason::IncludeDirective;
            }

            if ($hasComponentOpaqueContribution) {
                $reasons[] = BladeUncertaintyReason::ComponentTag;
            }

            $newAnalysis = BladeTemplateAnalysis::unknown(
                $reasons,
                $safety->analysis->unsafeKeys,
            );
        } else {
            $keys = \array_keys($combined);

            $newAnalysis = $keys === []
                ? BladeTemplateAnalysis::safe()
                : BladeTemplateAnalysis::unsafeKeys($keys);
        }

        $finalised[$viewName] = new BladeViewSafety($safety->viewName, $safety->path, $newAnalysis);
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
     * Per-key propagation rule for resolvable component edges. Unlike
     * include edges, anonymous components have no mergeData pass-through —
     * the child template only sees data the parent explicitly bound as a
     * named attribute. A child unsafe key K therefore contributes to the
     * parent's unsafe keys ONLY when K is present in the edge's explicit
     * attribute map.
     *
     * Static attributes (`<x-foo bar="literal" />`) appear in the edge's
     * explicit map with an empty parent-var list, so they count as
     * "explicitly bound to a non-parent value" — propagation contributes
     * nothing. Bound attributes (`<x-foo :bar="$user" />`) contribute the
     * top-level parent variables present in the expression.
     *
     * @param array<non-empty-string, list<non-empty-string>> $explicitKeyMap
     * @param array<non-empty-string, true>                   $combined
     *
     * @return array<non-empty-string, true>
     *
     * @psalm-pure
     */
    private static function propagateComponentChildKey(
        string $childKey,
        array $explicitKeyMap,
        array $combined,
    ): array {
        if ($childKey === 'attributes') {
            // The `$attributes` scope-local in an anonymous-component template
            // is a {@see \Illuminate\View\ComponentAttributeBag} that exposes
            // every parent-bound attribute via reads like
            // `$attributes->get('bio')`, `$attributes->only(['bio'])`, or
            // `$attributes->whereStartsWith('bi')`. Those reads return the
            // raw bound value WITHOUT HTML escaping (only `(string)$attributes`
            // / `$attributes->merge(...)->__toString()` escapes). When the
            // child template raw-echoes anything sourced from the bag, the
            // scanner records `attributes` as a child unsafe key — but the
            // parent never binds a literal `attributes` attribute (it's a
            // reserved name), so the standard "key must be in explicitKeyMap"
            // gate would drop the flow silently. Union every parent variable
            // bound by any attribute on this edge: any of them could surface
            // through the bag.
            foreach ($explicitKeyMap as $parentVars) {
                foreach ($parentVars as $parentVar) {
                    $combined[$parentVar] = true;
                }
            }

            return $combined;
        }

        if (!isset($explicitKeyMap[$childKey])) {
            // No explicit binding — component never receives this key, so
            // no contribution to the parent's unsafe keys.
            return $combined;
        }

        foreach ($explicitKeyMap[$childKey] as $parentVar) {
            $combined[$parentVar] = true;
        }

        return $combined;
    }

    /**
     * Project a non-eligible safety record onto its post-propagation form by
     * removing the intermediate {@see BladeUncertaintyReason::IncludeResolved}
     * and {@see BladeUncertaintyReason::ComponentResolved} markers. The
     * remaining uncertainty list (LayoutSectionFlow, ComponentTag,
     * IncludeDirective, etc.) already dominates the result, and the enum
     * documents that the intermediate markers are never exposed to map
     * consumers.
     *
     * Returns the original safety unchanged when no intermediate marker is
     * present — the common case for templates whose only uncertainty is
     * something other than an include directive or a resolvable component
     * tag.
     *
     * @psalm-pure
     */
    private static function stripIntermediateMarkers(BladeViewSafety $safety): BladeViewSafety
    {
        $uncertainties = $safety->analysis->uncertainties;
        $filtered = [];
        $sawIntermediate = false;

        foreach ($uncertainties as $reason) {
            if ($reason->isIntermediate()) {
                $sawIntermediate = true;
                continue;
            }

            $filtered[] = $reason;
        }

        if (!$sawIntermediate) {
            return $safety;
        }

        if ($filtered === []) {
            // Defensive: a template flagged non-eligible MUST have at least
            // one non-intermediate uncertainty (that is what makes it
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
     * True if a template is a candidate for edge propagation: every
     * uncertainty is one of the intermediate markers
     * ({@see BladeUncertaintyReason::IncludeResolved},
     * {@see BladeUncertaintyReason::ComponentResolved}), so the propagation
     * pass can soundly fold child contributions back into a SAFE /
     * UNSAFE_KEYS result.
     *
     * Templates with any other uncertainty (LayoutSectionFlow,
     * IncludeDirective, ComponentTag, etc.) stay UNKNOWN regardless, so
     * propagation would be a no-op anyway.
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
            if (!$uncertainty->isIntermediate()) {
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
