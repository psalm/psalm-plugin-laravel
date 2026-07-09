<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\IgnoreDeprecations;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Config\ColumnFallback;
use Psalm\LaravelPlugin\Config\ExperimentalFeature;
use Psalm\LaravelPlugin\Config\PluginConfig;
use Psalm\LaravelPlugin\Plugin;

#[CoversClass(PluginConfig::class)]
#[CoversClass(ColumnFallback::class)]
#[CoversClass(ExperimentalFeature::class)]
#[CoversClass(Plugin::class)]
final class PluginConfigTest extends TestCase
{
    private ?string $originalEnv = null;

    protected function setUp(): void
    {
        $env = \getenv('PSALM_LARAVEL_PLUGIN_CACHE_PATH');
        $this->originalEnv = $env !== false ? $env : null;
    }

    protected function tearDown(): void
    {
        if ($this->originalEnv !== null) {
            \putenv('PSALM_LARAVEL_PLUGIN_CACHE_PATH=' . $this->originalEnv);
        } else {
            \putenv('PSALM_LARAVEL_PLUGIN_CACHE_PATH');
        }
    }

    #[Test]
    public function defaults_when_no_xml(): void
    {
        $config = PluginConfig::fromXml(null);

        $this->assertSame(ColumnFallback::Migrations, $config->modelPropertiesColumnFallback);
        $this->assertFalse($config->failOnInternalError);
        $this->assertFalse($config->findMissingTranslations);
        $this->assertFalse($config->findMissingViews);
        $this->assertFalse($config->reportImplicitQueryBuilderCalls);
        $this->assertFalse($config->findUndefinedRelations);
        // null = auto-detect via class_exists('Laravel\Octane\Octane') at runtime;
        // explicit true/false in XML overrides the auto-detection.
        $this->assertNull($config->findOctaneIncompatibleBinding);
        $this->assertTrue($config->resolveDynamicWhereClauses);
        $this->assertTrue($config->resolveConfigReturnTypes);
        $this->assertSame([], $config->configDirectories);
        $this->assertSame([], $config->experimentalFeatures);
        $this->assertFalse($config->isExperimentEnabled(ExperimentalFeature::ModelToArrayShape));
        $this->assertSame([], $config->experimentalNotices);
    }

    #[Test]
    public function config_directories_single_entry(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><configDirectory name="app/Config" /></pluginClass>');

        $config = PluginConfig::fromXml($xml);

        $this->assertSame(['app/Config'], $config->configDirectories);
    }

    #[Test]
    public function config_directories_multiple_entries_preserve_order(): void
    {
        $xml = new \SimpleXMLElement(
            '<pluginClass>'
            . '<configDirectory name="app/Config" />'
            . '<configDirectory name="packages/*/config" />'
            . '<configDirectory name="vendor/foo/bar/config" />'
            . '</pluginClass>',
        );

        $config = PluginConfig::fromXml($xml);

        $this->assertSame(
            ['app/Config', 'packages/*/config', 'vendor/foo/bar/config'],
            $config->configDirectories,
        );
    }

    #[Test]
    public function config_directories_throw_on_empty_name_attribute(): void
    {
        $xml = new \SimpleXMLElement(
            '<pluginClass>'
            . '<configDirectory name="app/Config" />'
            . '<configDirectory name="" />'
            . '</pluginClass>',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/<configDirectory> requires a non-empty `name` attribute/');

        PluginConfig::fromXml($xml);
    }

    #[Test]
    public function config_directories_throw_on_missing_name_attribute(): void
    {
        // Catches typos like <configDirectory path="..." /> where the user used the wrong
        // attribute name — without this guard the element is silently dropped and the
        // typo-warning behaviour kicks in only when *every* entry is malformed.
        $xml = new \SimpleXMLElement(
            '<pluginClass>'
            . '<configDirectory name="app/Config" />'
            . '<configDirectory path="packages/forms/config" />'
            . '</pluginClass>',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/<configDirectory> requires a non-empty `name` attribute/');

        PluginConfig::fromXml($xml);
    }

    #[Test]
    public function experimental_absent_element_enables_nothing(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass />');

        $config = PluginConfig::fromXml($xml);

        $this->assertSame([], $config->experimentalFeatures);
        $this->assertSame([], $config->experimentalNotices);
    }

    #[Test]
    public function experimental_granular_named_feature_enables_only_that_feature(): void
    {
        // Granular mode (no all attribute): only the named <feature> is enabled.
        $xml = new \SimpleXMLElement(
            '<pluginClass><experimental><feature name="modelToArrayShape" /></experimental></pluginClass>',
        );

        $config = PluginConfig::fromXml($xml);

        $this->assertSame([ExperimentalFeature::ModelToArrayShape], $config->experimentalFeatures);
        $this->assertTrue($config->isExperimentEnabled(ExperimentalFeature::ModelToArrayShape));
        $this->assertSame([], $config->experimentalNotices);
    }

    #[Test]
    public function experimental_duplicate_feature_names_dedupe(): void
    {
        $xml = new \SimpleXMLElement(
            '<pluginClass>'
            . '<experimental>'
            . '<feature name="modelToArrayShape" />'
            . '<feature name="modelToArrayShape" />'
            . '</experimental>'
            . '</pluginClass>',
        );

        $config = PluginConfig::fromXml($xml);

        $this->assertSame([ExperimentalFeature::ModelToArrayShape], $config->experimentalFeatures);
    }

    #[Test]
    public function experimental_all_true_enables_every_case(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><experimental all="true" /></pluginClass>');

        $config = PluginConfig::fromXml($xml);

        // all="true" is resolved to every case at parse time — experimentalFeatures IS cases().
        $this->assertSame(ExperimentalFeature::cases(), $config->experimentalFeatures);
        $this->assertSame([], $config->experimentalNotices);

        foreach (ExperimentalFeature::cases() as $case) {
            $this->assertTrue($config->isExperimentEnabled($case));
        }
    }

    #[Test]
    public function experimental_all_true_with_exclude_disables_that_feature(): void
    {
        // Excluding the only live case leaves an empty (but legitimate) enabled list — no notice.
        $xml = new \SimpleXMLElement(
            '<pluginClass><experimental all="true"><exclude name="modelToArrayShape" /></experimental></pluginClass>',
        );

        $config = PluginConfig::fromXml($xml);

        $this->assertSame([], $config->experimentalFeatures);
        $this->assertFalse($config->isExperimentEnabled(ExperimentalFeature::ModelToArrayShape));
        $this->assertSame([], $config->experimentalNotices);
    }

    #[Test]
    public function experimental_duplicate_excludes_dedupe(): void
    {
        $xml = new \SimpleXMLElement(
            '<pluginClass>'
            . '<experimental all="true">'
            . '<exclude name="modelToArrayShape" />'
            . '<exclude name="modelToArrayShape" />'
            . '</experimental>'
            . '</pluginClass>',
        );

        $config = PluginConfig::fromXml($xml);

        // Both excludes name the only case, so the enabled list is empty either way — the point
        // is that the duplicate is tolerated rather than mishandled.
        $this->assertSame([], $config->experimentalFeatures);
    }

    #[Test]
    public function experimental_feature_under_all_true_throws(): void
    {
        // Mode exclusivity: <feature> is redundant under all="true"; the parser rejects it rather
        // than silently ignoring it.
        $xml = new \SimpleXMLElement(
            '<pluginClass><experimental all="true"><feature name="modelToArrayShape" /></experimental></pluginClass>',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/<feature> is redundant under <experimental all="true">/');

        PluginConfig::fromXml($xml);
    }

    #[Test]
    public function experimental_exclude_without_all_true_throws(): void
    {
        // Mode exclusivity: <exclude> only makes sense under all="true".
        $xml = new \SimpleXMLElement(
            '<pluginClass><experimental><exclude name="modelToArrayShape" /></experimental></pluginClass>',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/<exclude> requires <experimental all="true">/');

        PluginConfig::fromXml($xml);
    }

    #[Test]
    public function experimental_unexpected_child_element_under_all_true_throws(): void
    {
        $xml = new \SimpleXMLElement(
            '<pluginClass><experimental all="true"><include name="modelToArrayShape" /></experimental></pluginClass>',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unexpected <include> element under <experimental all="true">/');

        PluginConfig::fromXml($xml);
    }

    #[Test]
    public function experimental_unexpected_child_element_in_granular_mode_throws(): void
    {
        $xml = new \SimpleXMLElement(
            '<pluginClass><experimental><include name="modelToArrayShape" /></experimental></pluginClass>',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Unexpected <include> element under <experimental>/');

        PluginConfig::fromXml($xml);
    }

    #[Test]
    public function experimental_invalid_all_value_throws(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><experimental all="banana" /></pluginClass>');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid experimental all value 'banana'");

        PluginConfig::fromXml($xml);
    }

    #[Test]
    public function experimental_unknown_exclude_name_close_to_a_valid_one_throws_with_a_suggestion(): void
    {
        // "modelToArrayShapes" is edit-distance 1 from the real "modelToArrayShape", so the
        // gated "Did you mean" hint fires on the exclude name.
        $xml = new \SimpleXMLElement(
            '<pluginClass><experimental all="true"><exclude name="modelToArrayShapes" /></experimental></pluginClass>',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches(
            "/Unknown experimental feature 'modelToArrayShapes'\\. Did you mean 'modelToArrayShape'\\? "
            . "Valid values: 'modelToArrayShape'\\./",
        );

        PluginConfig::fromXml($xml);
    }

    #[Test]
    public function experimental_unknown_exclude_name_far_from_any_valid_one_omits_the_suggestion(): void
    {
        // "foo" is nowhere near any real feature name, so no misleading "Did you mean" hint —
        // but the valid-values list is still shown so the user can self-correct.
        $xml = new \SimpleXMLElement(
            '<pluginClass><experimental all="true"><exclude name="foo" /></experimental></pluginClass>',
        );

        try {
            PluginConfig::fromXml($xml);
            $this->fail('Expected InvalidArgumentException for an unknown exclude name.');
        } catch (\InvalidArgumentException $invalidArgumentException) {
            $this->assertStringContainsString("Unknown experimental feature 'foo'.", $invalidArgumentException->getMessage());
            $this->assertStringContainsString("Valid values: 'modelToArrayShape'.", $invalidArgumentException->getMessage());
            $this->assertStringNotContainsString('Did you mean', $invalidArgumentException->getMessage());
        }
    }

    #[Test]
    public function experimental_unknown_feature_name_close_to_a_valid_one_throws_with_a_suggestion(): void
    {
        // Same gated "Did you mean" hint, now on a granular <feature> name.
        $xml = new \SimpleXMLElement(
            '<pluginClass><experimental><feature name="modelToArrayShapes" /></experimental></pluginClass>',
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches(
            "/Unknown experimental feature 'modelToArrayShapes'\\. Did you mean 'modelToArrayShape'\\? "
            . "Valid values: 'modelToArrayShape'\\./",
        );

        PluginConfig::fromXml($xml);
    }

    #[Test]
    public function closest_by_levenshtein_prefers_the_nearer_of_several_candidates(): void
    {
        // With only one live ExperimentalFeature case today, driving the "did you mean" logic
        // through fromXml() alone never gives it a second candidate to prefer over the first —
        // see closestByLevenshtein()'s docblock. Reflection on this specific pure, generic
        // algorithm (unrelated to fromXml()'s own XML-parsing business logic) is how the
        // multi-candidate comparison gets real coverage without waiting for a second feature.
        $method = new \ReflectionMethod(PluginConfig::class, 'closestByLevenshtein');

        $this->assertSame('sunday', $method->invoke(null, 'sundy', ['monday', 'sunday', 'tuesday']));
        $this->assertSame('only', $method->invoke(null, 'anything', ['only']));
    }

    #[Test]
    public function retirement_notice_returns_null_for_a_name_that_was_never_a_feature(): void
    {
        // RETIRED is genuinely empty today (no feature has graduated or been withdrawn yet), so
        // every lookup returns null. The moment a future PR adds an entry, resolveExperimentalFeatureName()
        // surfaces that notice instead of throwing.
        $this->assertNull(ExperimentalFeature::retirementNotice('neverExisted'));
        $this->assertNull(ExperimentalFeature::retirementNotice('modelToArrayShape'));
    }

    #[Test]
    public function experimental_exclude_missing_name_attribute_throws(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><experimental all="true"><exclude /></experimental></pluginClass>');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/<exclude> requires a non-empty `name` attribute/');

        PluginConfig::fromXml($xml);
    }

    #[Test]
    public function experimental_feature_missing_name_attribute_throws(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><experimental><feature /></experimental></pluginClass>');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/<feature> requires a non-empty `name` attribute/');

        PluginConfig::fromXml($xml);
    }

    #[Test]
    public function experimental_childless_collects_a_notice_and_enables_nothing(): void
    {
        // No #[IgnoreDeprecations]: this notice is collected into experimentalNotices, not
        // raised via trigger_error() — trigger_error(E_USER_DEPRECATED) here would be turned
        // into a thrown exception by Psalm's own CLI error handler during a real run, crashing
        // the whole analysis instead of emitting a soft notice.
        $xml = new \SimpleXMLElement('<pluginClass><experimental /></pluginClass>');

        $config = PluginConfig::fromXml($xml);

        $this->assertSame([], $config->experimentalFeatures);
        $this->assertSame(
            ['<experimental> has no effect: it has no <feature> children and no all="true" attribute. '
                . 'Enable specific features with <feature name="..." />, or all of them with '
                . '<experimental all="true" />. See docs/config.md.'],
            $config->experimentalNotices,
        );
    }

    #[Test]
    public function experimental_all_false_with_feature_children_enables_granularly(): void
    {
        // all="false" is just granular mode spelled out — the <feature> children still enable.
        $xml = new \SimpleXMLElement(
            '<pluginClass><experimental all="false"><feature name="modelToArrayShape" /></experimental></pluginClass>',
        );

        $config = PluginConfig::fromXml($xml);

        $this->assertSame([ExperimentalFeature::ModelToArrayShape], $config->experimentalFeatures);
        $this->assertSame([], $config->experimentalNotices);
    }

    #[Test]
    public function retired_names_never_overlap_a_live_case(): void
    {
        // Lifecycle invariant (see ExperimentalFeature's class docblock): each name lives in
        // exactly one of {live case, RETIRED}. Nothing in the type system enforces this —
        // resolveExperimentalFeatureName() checks tryFrom() first, so a name left as both a live
        // case AND a RETIRED entry would silently never produce the retirement notice. Locking
        // this in now, ahead of the first real graduation/withdrawal.
        $retired = (new \ReflectionClassConstant(ExperimentalFeature::class, 'RETIRED'))->getValue();

        $this->assertIsArray($retired);

        foreach (ExperimentalFeature::cases() as $case) {
            $this->assertArrayNotHasKey($case->value, $retired, "Live case '{$case->value}' must not also appear in RETIRED.");
        }
    }

    #[Test]
    public function resolve_lenient_all_true_applies_excludes(): void
    {
        // Read-only twin of fromXml()'s strict parser, but never throws: all="true" resolves to
        // every case; unrecognized exclude names are ignored rather than rejected.
        $allTrue = new \SimpleXMLElement('<experimental all="true" />');
        $this->assertSame(ExperimentalFeature::cases(), ExperimentalFeature::resolveLenient($allTrue));

        $excludeUnknown = new \SimpleXMLElement(
            '<experimental all="true"><exclude name="doesNotExist" /></experimental>',
        );
        $this->assertSame(ExperimentalFeature::cases(), ExperimentalFeature::resolveLenient($excludeUnknown));

        $excludeKnown = new \SimpleXMLElement(
            '<experimental all="true"><exclude name="modelToArrayShape" /></experimental>',
        );
        $this->assertSame([], ExperimentalFeature::resolveLenient($excludeKnown));
    }

    #[Test]
    public function resolve_lenient_granular_reads_feature_children(): void
    {
        // Granular mode: recognized <feature> names, deduped; unknown names ignored (never thrown).
        $granular = new \SimpleXMLElement(
            '<experimental>'
            . '<feature name="modelToArrayShape" />'
            . '<feature name="doesNotExist" />'
            . '<feature name="modelToArrayShape" />'
            . '</experimental>',
        );
        $this->assertSame([ExperimentalFeature::ModelToArrayShape], ExperimentalFeature::resolveLenient($granular));

        // Mode-mismatched children are ignored rather than throwing: an <exclude> in granular mode
        // (or a <feature> under all="true") is simply not read.
        $childless = new \SimpleXMLElement('<experimental />');
        $this->assertSame([], ExperimentalFeature::resolveLenient($childless));

        $excludeWithoutAll = new \SimpleXMLElement('<experimental><exclude name="modelToArrayShape" /></experimental>');
        $this->assertSame([], ExperimentalFeature::resolveLenient($excludeWithoutAll));
    }

    #[Test]
    public function column_fallback_none(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><modelProperties columnFallback="none" /></pluginClass>');

        $config = PluginConfig::fromXml($xml);

        $this->assertSame(ColumnFallback::None, $config->modelPropertiesColumnFallback);
    }

    #[Test]
    public function column_fallback_migrations(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><modelProperties columnFallback="migrations" /></pluginClass>');

        $config = PluginConfig::fromXml($xml);

        $this->assertSame(ColumnFallback::Migrations, $config->modelPropertiesColumnFallback);
    }

    #[Test]
    public function invalid_column_fallback_throws(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><modelProperties columnFallback="invalid" /></pluginClass>');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid columnFallback value 'invalid'");

        PluginConfig::fromXml($xml);
    }

    #[Test]
    public function fail_on_internal_error_true(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><failOnInternalError value="true" /></pluginClass>');

        $config = PluginConfig::fromXml($xml);

        $this->assertTrue($config->failOnInternalError);
    }

    #[Test]
    public function fail_on_internal_error_false(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><failOnInternalError value="false" /></pluginClass>');

        $config = PluginConfig::fromXml($xml);

        $this->assertFalse($config->failOnInternalError);
    }

    #[Test]
    public function invalid_fail_on_internal_error_throws(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><failOnInternalError value="yes" /></pluginClass>');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid failOnInternalError value 'yes'");

        PluginConfig::fromXml($xml);
    }

    #[Test]
    public function find_missing_translations_true(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><findMissingTranslations value="true" /></pluginClass>');

        $config = PluginConfig::fromXml($xml);

        $this->assertTrue($config->findMissingTranslations);
    }

    #[Test]
    public function find_missing_translations_false(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><findMissingTranslations value="false" /></pluginClass>');

        $config = PluginConfig::fromXml($xml);

        $this->assertFalse($config->findMissingTranslations);
    }

    #[Test]
    public function invalid_find_missing_translations_throws(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><findMissingTranslations value="yes" /></pluginClass>');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid findMissingTranslations value 'yes'");

        PluginConfig::fromXml($xml);
    }

    #[Test]
    public function find_missing_views_true(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><findMissingViews value="true" /></pluginClass>');

        $config = PluginConfig::fromXml($xml);

        $this->assertTrue($config->findMissingViews);
    }

    #[Test]
    public function find_missing_views_false(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><findMissingViews value="false" /></pluginClass>');

        $config = PluginConfig::fromXml($xml);

        $this->assertFalse($config->findMissingViews);
    }

    #[Test]
    public function report_implicit_query_builder_calls_true(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><reportImplicitQueryBuilderCalls value="true" /></pluginClass>');

        $config = PluginConfig::fromXml($xml);

        $this->assertTrue($config->reportImplicitQueryBuilderCalls);
    }

    #[Test]
    public function report_implicit_query_builder_calls_false(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><reportImplicitQueryBuilderCalls value="false" /></pluginClass>');

        $config = PluginConfig::fromXml($xml);

        $this->assertFalse($config->reportImplicitQueryBuilderCalls);
    }

    #[Test]
    public function find_undefined_relations_true(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><findUndefinedRelations value="true" /></pluginClass>');

        $config = PluginConfig::fromXml($xml);

        $this->assertTrue($config->findUndefinedRelations);
    }

    #[Test]
    public function find_undefined_relations_false(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><findUndefinedRelations value="false" /></pluginClass>');

        $config = PluginConfig::fromXml($xml);

        $this->assertFalse($config->findUndefinedRelations);
    }

    #[Test]
    public function invalid_find_undefined_relations_throws(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><findUndefinedRelations value="maybe" /></pluginClass>');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid findUndefinedRelations value 'maybe'");

        PluginConfig::fromXml($xml);
    }

    #[Test]
    public function invalid_report_implicit_query_builder_calls_throws(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><reportImplicitQueryBuilderCalls value="yes" /></pluginClass>');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid reportImplicitQueryBuilderCalls value 'yes'");

        PluginConfig::fromXml($xml);
    }

    #[Test]
    public function invalid_find_missing_views_throws(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><findMissingViews value="yes" /></pluginClass>');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid findMissingViews value 'yes'");

        PluginConfig::fromXml($xml);
    }

    #[Test]
    public function find_octane_incompatible_binding_absent_yields_null(): void
    {
        // null is the auto-detect sentinel — Plugin::registerHandlers() falls
        // back to class_exists('Laravel\Octane\Octane') when this is null.
        $xml = new \SimpleXMLElement('<pluginClass />');

        $config = PluginConfig::fromXml($xml);

        $this->assertNull($config->findOctaneIncompatibleBinding);
    }

    #[Test]
    public function find_octane_incompatible_binding_true(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><findOctaneIncompatibleBinding value="true" /></pluginClass>');

        $config = PluginConfig::fromXml($xml);

        $this->assertTrue($config->findOctaneIncompatibleBinding);
    }

    #[Test]
    public function find_octane_incompatible_binding_false(): void
    {
        // Explicit false overrides auto-detect even when laravel/octane is installed.
        $xml = new \SimpleXMLElement('<pluginClass><findOctaneIncompatibleBinding value="false" /></pluginClass>');

        $config = PluginConfig::fromXml($xml);

        $this->assertFalse($config->findOctaneIncompatibleBinding);
    }

    #[Test]
    public function invalid_find_octane_incompatible_binding_throws(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><findOctaneIncompatibleBinding value="yes" /></pluginClass>');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid findOctaneIncompatibleBinding value 'yes'");

        PluginConfig::fromXml($xml);
    }

    #[Test]
    public function dynamic_where_methods_true(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><resolveDynamicWhereClauses value="true" /></pluginClass>');

        $config = PluginConfig::fromXml($xml);

        $this->assertTrue($config->resolveDynamicWhereClauses);
    }

    #[Test]
    public function dynamic_where_methods_false(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><resolveDynamicWhereClauses value="false" /></pluginClass>');

        $config = PluginConfig::fromXml($xml);

        $this->assertFalse($config->resolveDynamicWhereClauses);
    }

    #[Test]
    public function invalid_dynamic_where_methods_throws(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><resolveDynamicWhereClauses value="yes" /></pluginClass>');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid resolveDynamicWhereClauses value 'yes'");

        PluginConfig::fromXml($xml);
    }

    #[Test]
    public function resolve_config_return_types_true(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><resolveConfigReturnTypes value="true" /></pluginClass>');

        $config = PluginConfig::fromXml($xml);

        $this->assertTrue($config->resolveConfigReturnTypes);
    }

    #[Test]
    public function resolve_config_return_types_false(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><resolveConfigReturnTypes value="false" /></pluginClass>');

        $config = PluginConfig::fromXml($xml);

        $this->assertFalse($config->resolveConfigReturnTypes);
    }

    #[Test]
    public function invalid_resolve_config_return_types_throws(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><resolveConfigReturnTypes value="yes" /></pluginClass>');

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage("Invalid resolveConfigReturnTypes value 'yes'");

        PluginConfig::fromXml($xml);
    }

    #[Test]
    #[IgnoreDeprecations]
    public function cache_path_uses_env_var(): void
    {
        \putenv('PSALM_LARAVEL_PLUGIN_CACHE_PATH=/tmp/psalm-test-custom');

        $config = PluginConfig::fromXml(null);

        $this->assertSame('/tmp/psalm-test-custom', $config->cachePath);
    }

    #[Test]
    #[IgnoreDeprecations]
    public function cache_path_trims_trailing_separator(): void
    {
        \putenv('PSALM_LARAVEL_PLUGIN_CACHE_PATH=/tmp/psalm-test-custom/');

        $config = PluginConfig::fromXml(null);

        $this->assertSame('/tmp/psalm-test-custom', $config->cachePath);
    }

    #[Test]
    public function cache_path_uses_temp_dir_by_default(): void
    {
        \putenv('PSALM_LARAVEL_PLUGIN_CACHE_PATH');

        $config = PluginConfig::fromXml(null);

        $expectedPrefix = \sys_get_temp_dir() . \DIRECTORY_SEPARATOR . 'psalm-laravel-';
        $this->assertStringStartsWith($expectedPrefix, $config->cachePath);
    }

    #[Test]
    public function cache_path_is_deterministic(): void
    {
        \putenv('PSALM_LARAVEL_PLUGIN_CACHE_PATH');

        $first = PluginConfig::fromXml(null);
        $second = PluginConfig::fromXml(null);

        $this->assertSame($first->cachePath, $second->cachePath);
    }

    #[Test]
    #[IgnoreDeprecations]
    public function get_cache_location_creates_and_returns_dir(): void
    {
        \putenv('PSALM_LARAVEL_PLUGIN_CACHE_PATH=/tmp/psalm-test-cache-loc');

        $config = PluginConfig::fromXml(null);
        $location = Plugin::getCacheLocation($config);

        $this->assertSame('/tmp/psalm-test-cache-loc', $location);
    }

    #[Test]
    #[IgnoreDeprecations]
    public function get_alias_stub_location_ends_with_filename(): void
    {
        \putenv('PSALM_LARAVEL_PLUGIN_CACHE_PATH=/tmp/psalm-test-cache');

        $config = PluginConfig::fromXml(null);
        $location = Plugin::getAliasStubLocation($config);

        $this->assertSame('/tmp/psalm-test-cache' . \DIRECTORY_SEPARATOR . 'aliases.phpstub', $location);
    }

    #[Test]
    #[IgnoreDeprecations]
    public function full_config(): void
    {
        \putenv('PSALM_LARAVEL_PLUGIN_CACHE_PATH=/tmp/psalm-test');

        $xml = new \SimpleXMLElement(
            '<pluginClass>'
            . '<modelProperties columnFallback="none" />'
            . '<resolveDynamicWhereClauses value="false" />'
            . '<resolveConfigReturnTypes value="false" />'
            . '<failOnInternalError value="true" />'
            . '<findMissingTranslations value="true" />'
            . '<findMissingViews value="true" />'
            . '<findUndefinedRelations value="true" />'
            . '<configDirectory name="app/Config" />'
            . '<configDirectory name="packages/*/config" />'
            . '</pluginClass>',
        );

        $config = PluginConfig::fromXml($xml);

        $this->assertSame(ColumnFallback::None, $config->modelPropertiesColumnFallback);
        $this->assertFalse($config->resolveDynamicWhereClauses);
        $this->assertFalse($config->resolveConfigReturnTypes);
        $this->assertTrue($config->findMissingTranslations);
        $this->assertTrue($config->findMissingViews);
        $this->assertTrue($config->findUndefinedRelations);
        $this->assertSame('/tmp/psalm-test', $config->cachePath);
        $this->assertTrue($config->failOnInternalError);
        $this->assertSame(['app/Config', 'packages/*/config'], $config->configDirectories);
    }

    #[Test]
    public function report_active_experiments_writes_the_active_features_line(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><experimental all="true" /></pluginClass>');
        $config = PluginConfig::fromXml($xml);
        $progress = $this->spyProgress();

        (new \ReflectionMethod(Plugin::class, 'reportActiveExperiments'))->invoke(new Plugin(), $config, $progress);

        $this->assertSame(
            ["Laravel plugin: experimental features enabled: modelToArrayShape\n"],
            $progress->writes,
        );
    }

    #[Test]
    public function report_active_experiments_writes_nothing_when_no_features_are_active(): void
    {
        $config = PluginConfig::fromXml(null);
        $progress = $this->spyProgress();

        (new \ReflectionMethod(Plugin::class, 'reportActiveExperiments'))->invoke(new Plugin(), $config, $progress);

        $this->assertSame([], $progress->writes);
    }

    #[Test]
    public function report_active_experiments_forwards_notices_as_warnings(): void
    {
        $xml = new \SimpleXMLElement('<pluginClass><experimental /></pluginClass>');
        $config = PluginConfig::fromXml($xml);
        $progress = $this->spyProgress();

        (new \ReflectionMethod(Plugin::class, 'reportActiveExperiments'))->invoke(new Plugin(), $config, $progress);

        // Progress::warning() delegates to write() with a "Warning: " prefix and a trailing EOL —
        // confirms the notice is actually forwarded through that channel, not silently dropped.
        $this->assertSame(
            ['Warning: <experimental> has no effect: it has no <feature> children and no all="true" attribute. '
                . 'Enable specific features with <feature name="..." />, or all of them with '
                . '<experimental all="true" />. See docs/config.md.' . \PHP_EOL],
            $progress->writes,
        );
    }

    /**
     * Minimal concrete Progress that records every write() call (including the ones warning()
     * delegates to) instead of writing to STDERR, so reportActiveExperiments()'s actual output
     * is directly assertable.
     */
    private function spyProgress(): \Psalm\Progress\Progress
    {
        return new class extends \Psalm\Progress\Progress {
            /** @var list<string> */
            public array $writes = [];

            #[\Override]
            public function debug(string $message): void {}

            #[\Override]
            public function startPhase(\Psalm\Progress\Phase $phase, int $threads = 1): void {}

            #[\Override]
            public function expand(int $number_of_tasks): void {}

            #[\Override]
            public function taskDone(int $level): void {}

            #[\Override]
            public function finish(): void {}

            #[\Override]
            public function alterFileDone(string $file_name): void {}

            #[\Override]
            public function write(string $message): void
            {
                $this->writes[] = $message;
            }
        };
    }
}
