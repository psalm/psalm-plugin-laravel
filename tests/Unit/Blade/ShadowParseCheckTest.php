<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\ShadowParseCheck;

#[CoversClass(ShadowParseCheck::class)]
final class ShadowParseCheckTest extends TestCase
{
    #[Test]
    public function a_clean_shadow_parses_and_a_broken_one_does_not(): void
    {
        $check = new ShadowParseCheck(80400);

        $this->assertTrue($check->parses("<?php echo 1; ?>\n<p>x</p>\n"));
        $this->assertFalse($check->parses("<?php echo e([1, , 2]); ?>\n"));
    }

    #[Test]
    public function the_verdict_follows_the_analysis_php_version(): void
    {
        // `match` became a keyword in PHP 8.0, so the same bytes parse for 7.4 only.
        $contents = "<?php function match() {} ?>\n";

        $this->assertTrue((new ShadowParseCheck(70400))->parses($contents));
        $this->assertFalse((new ShadowParseCheck(80000))->parses($contents));
    }
}
