<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Util;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Util\EloquentModelMethods;

/**
 * Direct coverage for the pure name classifiers. They were promoted from private SuppressHandler helpers
 * to a shared util now consumed by three handlers, so the subtle StudlyCase gate and the `.+` accessor
 * guard get a dedicated truth table here rather than being exercised only through the full Psalm pipeline.
 */
#[CoversClass(EloquentModelMethods::class)]
final class EloquentModelMethodsTest extends TestCase
{
    #[Test]
    public function isLegacyScopeMethodName_recognises_studly_scopes(): void
    {
        $this->assertTrue(EloquentModelMethods::isLegacyScopeMethodName('scopepublished', 'scopePublished'));
        $this->assertTrue(EloquentModelMethods::isLegacyScopeMethodName('scopeactive', 'scopeActive'));
        // Single-letter scope name is still StudlyCase after `scope`.
        $this->assertTrue(EloquentModelMethods::isLegacyScopeMethodName('scopex', 'scopeX'));
    }

    #[Test]
    public function isLegacyScopeMethodName_rejects_non_scopes(): void
    {
        // Bare `scope()` is excluded by the length guard (< 6 chars).
        $this->assertFalse(EloquentModelMethods::isLegacyScopeMethodName('scope', 'scope'));
        // Prefix-only collisions: the char after `scope` is lowercase, not StudlyCase.
        $this->assertFalse(EloquentModelMethods::isLegacyScopeMethodName('scoped', 'scoped'));
        $this->assertFalse(EloquentModelMethods::isLegacyScopeMethodName('scopes', 'scopes'));
        $this->assertFalse(EloquentModelMethods::isLegacyScopeMethodName('scopedquery', 'scopedQuery'));
        // All-lowercase `scopeactive()` is technically dispatchable but intentionally not matched:
        // the StudlyCase gate trades a near-zero-frequency miss for far fewer false positives.
        $this->assertFalse(EloquentModelMethods::isLegacyScopeMethodName('scopeactive', 'scopeactive'));
        // Not a scope prefix at all.
        $this->assertFalse(EloquentModelMethods::isLegacyScopeMethodName('published', 'published'));
        // Null cased name (no original spelling to inspect).
        $this->assertFalse(EloquentModelMethods::isLegacyScopeMethodName('scopepublished', null));
    }

    #[Test]
    public function isLegacyAccessorMethodName_recognises_get_and_set_attribute(): void
    {
        $this->assertTrue(EloquentModelMethods::isLegacyAccessorMethodName('gettitleattribute'));
        $this->assertTrue(EloquentModelMethods::isLegacyAccessorMethodName('settitleattribute'));
        $this->assertTrue(EloquentModelMethods::isLegacyAccessorMethodName('getforeignattribute'));
    }

    #[Test]
    public function isLegacyAccessorMethodName_excludes_bare_and_unrelated(): void
    {
        // The `.+` requires a middle segment, so the framework's own bare accessors are NOT matched.
        // A regression to `.*` would start flagging legitimate getAttribute()/setAttribute() overrides.
        $this->assertFalse(EloquentModelMethods::isLegacyAccessorMethodName('getattribute'));
        $this->assertFalse(EloquentModelMethods::isLegacyAccessorMethodName('setattribute'));
        // Must end exactly in `attribute`: plural and suffixed names are excluded.
        $this->assertFalse(EloquentModelMethods::isLegacyAccessorMethodName('getattributes'));
        $this->assertFalse(EloquentModelMethods::isLegacyAccessorMethodName('getattributevalue'));
        $this->assertFalse(EloquentModelMethods::isLegacyAccessorMethodName('setrawattributes'));
        // Real framework methods that merely start with get/set.
        $this->assertFalse(EloquentModelMethods::isLegacyAccessorMethodName('getroutekeyname'));
        $this->assertFalse(EloquentModelMethods::isLegacyAccessorMethodName('getmorphclass'));
        $this->assertFalse(EloquentModelMethods::isLegacyAccessorMethodName('title'));
    }
}
