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
    public function a_numeric_view_name_stays_an_int_key(): void
    {
        ViewReferenceRegistry::registerTemplate('123', 0, '/app/views/123.blade.php');

        $this->assertSame(123, \array_key_first(ViewReferenceRegistry::templates()));
    }

    #[Test]
    public function reset_clears_templates_from_a_previous_invocation(): void
    {
        ViewReferenceRegistry::registerTemplate('profile', 0, '/app/views/profile.blade.php');

        ViewReferenceRegistry::reset();

        $this->assertSame([], ViewReferenceRegistry::templates());
    }
}
