<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Tests\Unit\Blade;

use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\BladeTemplateAnalysis;
use Psalm\LaravelPlugin\Blade\BladeUncertaintyReason;
use Psalm\LaravelPlugin\Blade\BladeViewSafetyKind;

final class BladeTemplateAnalysisTest extends TestCase
{
    public function test_safe_factory_produces_safe_with_empty_payload(): void
    {
        $a = BladeTemplateAnalysis::safe();

        $this->assertSame(BladeViewSafetyKind::Safe, $a->kind);
        $this->assertSame([], $a->unsafeKeys);
        $this->assertSame([], $a->uncertainties);
    }

    public function test_unsafe_keys_factory_collapses_to_safe_on_empty_input(): void
    {
        // Load-bearing invariant: `analyze()` returning UNSAFE_KEYS with an
        // empty key list would be incoherent. Pinning the factory collapse so
        // a future refactor splitting `unsafeKeys()` and `safe()` cannot break
        // this without a test signalling.
        $a = BladeTemplateAnalysis::unsafeKeys([]);

        $this->assertSame(BladeViewSafetyKind::Safe, $a->kind);
        $this->assertSame([], $a->unsafeKeys);
    }

    public function test_unsafe_keys_factory_with_keys_produces_unsafe_kind(): void
    {
        $a = BladeTemplateAnalysis::unsafeKeys(['bio', 'html']);

        $this->assertSame(BladeViewSafetyKind::UnsafeKeys, $a->kind);
        $this->assertSame(['bio', 'html'], $a->unsafeKeys);
        $this->assertSame([], $a->uncertainties);
    }

    public function test_unknown_factory_preserves_observed_unsafe_keys(): void
    {
        // UNKNOWN dominates `kind`, but the scanner still emits the keys it
        // observed before hitting the unhandled construct. Handlers may use
        // them for diagnostics even when applying the whole-data fallback.
        $a = BladeTemplateAnalysis::unknown(
            [BladeUncertaintyReason::LayoutSectionFlow],
            ['bio'],
        );

        $this->assertSame(BladeViewSafetyKind::Unknown, $a->kind);
        $this->assertSame(['bio'], $a->unsafeKeys);
        $this->assertSame([BladeUncertaintyReason::LayoutSectionFlow], $a->uncertainties);
    }

    public function test_unknown_factory_omitting_keys_defaults_to_empty_list(): void
    {
        $a = BladeTemplateAnalysis::unknown([BladeUncertaintyReason::FileUnreadable]);

        $this->assertSame(BladeViewSafetyKind::Unknown, $a->kind);
        $this->assertSame([], $a->unsafeKeys);
        $this->assertSame([BladeUncertaintyReason::FileUnreadable], $a->uncertainties);
    }

    public function test_unknown_factory_rejects_empty_uncertainty_list(): void
    {
        // The non-empty-list contract is load-bearing: UNKNOWN with no
        // reasons would be indistinguishable from a safe template at the
        // handler layer and re-introduce the SAFE/UNKNOWN conflation this
        // class exists to prevent.
        $this->expectException(\InvalidArgumentException::class);

        BladeTemplateAnalysis::unknown([]);
    }
}
