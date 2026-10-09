<?php

declare(strict_types=1);

namespace BladeRuntimeHelpersFixture;

/**
 * Padding. Psalm forks analysis workers only when there are more files to analyze than workers
 * (`Internal/Codebase/Analyzer::doAnalysis()`), so without these the multi-threaded test runs
 * single-process and proves nothing. Do not trim the set without re-checking `assertForked()`.
 *
 * @psalm-api
 */
final class Padding2
{
    public function value(): int
    {
        return 2;
    }
}
