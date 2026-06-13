<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Blade;

/**
 * A {@see BladeTemplateAnalysis} carrying the view name and resolved file path
 * that identify which Blade template the result came from.
 *
 * Separating "analysis of this source string" ({@see BladeTemplateAnalysis})
 * from "this is the analysis for view X resolved at path Y" (this class) keeps
 * {@see BladeTemplateScanner} pure: the scanner never touches the filesystem,
 * the map never re-parses source.
 *
 * @psalm-api
 * @psalm-immutable
 */
final readonly class BladeViewSafety
{
    public function __construct(
        /** @var non-empty-string */
        public string $viewName,
        /** @var non-empty-string */
        public string $path,
        public BladeTemplateAnalysis $analysis,
    ) {}

    public function kind(): BladeViewSafetyKind
    {
        return $this->analysis->kind;
    }

    /** @return list<non-empty-string> */
    public function unsafeKeys(): array
    {
        return $this->analysis->unsafeKeys;
    }

    /** @return list<BladeUncertaintyReason> */
    public function uncertainties(): array
    {
        return $this->analysis->uncertainties;
    }
}
