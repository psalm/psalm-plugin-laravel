<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Util;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Util\EloquentModelMethods;

/**
 * Direct coverage for the pure name classifiers. They were promoted from private SuppressHandler helpers
 * to a shared util now consumed by three handlers, so the subtle StudlyCase gate, the `.+` accessor
 * guard, and the trait-boot static gate get a dedicated truth table here rather than being exercised
 * only through the full Psalm pipeline.
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

    #[Test]
    public function isTraitBootHook_recognises_conventional_hooks(): void
    {
        // Static `boot{Trait}` dispatched by Model::bootTraits().
        $this->assertTrue(EloquentModelMethods::isTraitBootHook('bootHasUuid', 'App\Concerns\HasUuid', true));
        // Instance `initialize{Trait}` dispatched by Model::initializeTraits() via `$this->{$method}()`.
        $this->assertTrue(EloquentModelMethods::isTraitBootHook('initializeHasUuid', 'App\Concerns\HasUuid', false));
        // initializeTraits() collects the hook regardless of the static modifier, so a static
        // `initialize{Trait}` is matched too (unlike the boot prefix, which is static-only).
        $this->assertTrue(EloquentModelMethods::isTraitBootHook('initializeHasUuid', 'App\Concerns\HasUuid', true));
        // Basename is the last namespace segment; an unqualified trait name resolves to itself.
        $this->assertTrue(EloquentModelMethods::isTraitBootHook('bootHasUuid', 'HasUuid', true));
    }

    #[Test]
    public function isTraitBootHook_rejects_non_hooks(): void
    {
        // The static gate: Model::bootTraits() only invokes a `boot{Trait}` when it is static, so a
        // non-static one is never dispatched and must stay flagged as the mis-declaration it is.
        $this->assertFalse(EloquentModelMethods::isTraitBootHook('bootHasUuid', 'App\Concerns\HasUuid', false));
        // Case-sensitive, mirroring Laravel's case-sensitive `in_array($method->getName(), $set)`
        // collection: a mis-cased `boothasuuid()` is never booted by Laravel (genuinely dead), so it
        // must stay flagged rather than be silently suppressed.
        $this->assertFalse(EloquentModelMethods::isTraitBootHook('boothasuuid', 'App\Concerns\HasUuid', true));
        $this->assertFalse(EloquentModelMethods::isTraitBootHook('initializehasuuid', 'App\Concerns\HasUuid', false));
        // Precision: a boot/initialize method whose suffix is not the declaring trait's basename is
        // never derived by Laravel from that trait, so it is genuinely dead code.
        $this->assertFalse(EloquentModelMethods::isTraitBootHook('bootSomethingElse', 'App\Concerns\HasUuid', true));
        $this->assertFalse(EloquentModelMethods::isTraitBootHook('initializeSomethingElse', 'App\Concerns\HasUuid', false));
        // Bare prefixes never match — no trait has an empty basename.
        $this->assertFalse(EloquentModelMethods::isTraitBootHook('boot', 'App\Concerns\HasUuid', true));
        $this->assertFalse(EloquentModelMethods::isTraitBootHook('initialize', 'App\Concerns\HasUuid', false));
        // Unrelated method.
        $this->assertFalse(EloquentModelMethods::isTraitBootHook('handle', 'App\Concerns\HasUuid', true));
        // Null cased name (no source spelling to match) is never treated as a hook.
        $this->assertFalse(EloquentModelMethods::isTraitBootHook(null, 'App\Concerns\HasUuid', true));
    }
}
