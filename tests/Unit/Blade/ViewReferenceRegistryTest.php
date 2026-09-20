<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\ViewReferenceRegistry;

#[CoversClass(ViewReferenceRegistry::class)]
final class ViewReferenceRegistryTest extends TestCase
{
    protected function setUp(): void
    {
        ViewReferenceRegistry::reset();
    }

    protected function tearDown(): void
    {
        ViewReferenceRegistry::reset();
    }

    #[Test]
    public function a_template_with_no_reference_is_unused(): void
    {
        ViewReferenceRegistry::registerTemplate('orphan', 0, '/app/views/orphan.blade.php');

        $unused = ViewReferenceRegistry::unusedTemplates();

        $this->assertArrayHasKey('orphan', $unused);
    }

    #[Test]
    public function a_referenced_template_is_not_unused(): void
    {
        ViewReferenceRegistry::registerTemplate('profile', 0, '/app/views/profile.blade.php');
        ViewReferenceRegistry::addReference('profile');

        $this->assertSame([], ViewReferenceRegistry::unusedTemplates());
    }

    /**
     * A published override (`resources/views/vendor/pkg/widget.blade.php`) legitimately owns two
     * names at once — `vendor.pkg.widget` and `pkg::widget` (see {@see \Psalm\LaravelPlugin\Blade\ViewName}) —
     * both pointing at the SAME template path. A call site referencing only the qualified form must
     * not leave the file flagged unused under its other, unreferenced name: that would be a false
     * positive on a template that IS rendered.
     */
    #[Test]
    public function a_template_referenced_under_one_of_its_two_names_is_not_unused_under_the_other(): void
    {
        ViewReferenceRegistry::registerTemplate('vendor.pkg.widget', 0, '/app/views/vendor/pkg/widget.blade.php');
        ViewReferenceRegistry::registerTemplate('pkg::widget', 1, '/app/views/vendor/pkg/widget.blade.php');
        ViewReferenceRegistry::addReference('pkg::widget');

        $this->assertSame([], ViewReferenceRegistry::unusedTemplates(), 'the qualified reference covers both names of the same file');
    }

    /**
     * The reverse of the case above must still catch a genuine orphan: two names for the same
     * physical file, NEITHER referenced, is reported once — not once per name.
     */
    #[Test]
    public function a_template_with_two_unreferenced_names_is_reported_once(): void
    {
        ViewReferenceRegistry::registerTemplate('vendor.pkg.widget', 0, '/app/views/vendor/pkg/widget.blade.php');
        ViewReferenceRegistry::registerTemplate('pkg::widget', 1, '/app/views/vendor/pkg/widget.blade.php');

        $unused = ViewReferenceRegistry::unusedTemplates();

        $this->assertCount(1, $unused, \var_export($unused, true));
    }

    #[Test]
    public function reset_clears_a_previous_invocations_templates_and_references(): void
    {
        ViewReferenceRegistry::registerTemplate('profile', 0, '/app/views/profile.blade.php');
        ViewReferenceRegistry::addReference('profile');
        ViewReferenceRegistry::markDynamic();

        ViewReferenceRegistry::reset();

        $this->assertSame([], ViewReferenceRegistry::unusedTemplates());
        $this->assertFalse(ViewReferenceRegistry::isDynamic());
    }
}
