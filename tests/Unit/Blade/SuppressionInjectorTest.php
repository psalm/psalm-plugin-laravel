<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\SuppressionInjector;

#[CoversClass(SuppressionInjector::class)]
final class SuppressionInjectorTest extends TestCase
{
    #[Test]
    public function inserts_suppress_inside_the_next_statement_php_block(): void
    {
        $shadow = "prelude\n<?php /* blade:2 */ ?><?php echo e(\$foo); ?>\n";
        $bladeSource = "{{-- @psalm-suppress UndefinedVariable --}}\n{{ \$foo }}\n";
        $lineMap = [1 => 0, 2 => 2];

        $result = (new SuppressionInjector())->inject($shadow, $bladeSource, $lineMap);

        $this->assertStringContainsString(
            '<?php /** @psalm-suppress UndefinedVariable */ echo e($foo); ?>',
            $result,
        );
        // No new lines added — the line map stays valid.
        $this->assertSame(\substr_count($shadow, "\n"), \substr_count($result, "\n"));
    }

    #[Test]
    public function drops_suppression_silently_when_nothing_follows(): void
    {
        $shadow = "<?php /* blade:1 */ ?>content\n";
        $bladeSource = "content\n{{-- @psalm-suppress Foo --}}\n";
        $lineMap = [1 => 1];

        $result = (new SuppressionInjector())->inject($shadow, $bladeSource, $lineMap);

        $this->assertSame($shadow, $result);
    }

    #[Test]
    public function leaves_content_untouched_when_no_suppress_comments(): void
    {
        $shadow = "<?php /* blade:1 */ ?>content\n";
        $bladeSource = "content\n";
        $lineMap = [1 => 1];

        $result = (new SuppressionInjector())->inject($shadow, $bladeSource, $lineMap);

        $this->assertSame($shadow, $result);
    }

    #[Test]
    public function resolve_keys_a_suppression_by_the_template_line_it_lands_on(): void
    {
        // Not by line 1, where the comment sits: the issue remap matches suppressions against the
        // template line an issue was mapped to, and Blade compiles the comment itself away.
        $shadow = "prelude\n<?php /* blade:2 */ ?><?php echo e(\$foo); ?>\n";
        $bladeSource = "{{-- @psalm-suppress UndefinedVariable --}}\n{{ \$foo }}\n";
        $lineMap = [1 => 0, 2 => 2];

        $this->assertSame(
            [2 => ['UndefinedVariable']],
            (new SuppressionInjector())->resolve($shadow, $bladeSource, $lineMap),
        );
    }

    #[Test]
    public function resolve_drops_a_suppression_with_nothing_to_attach_to(): void
    {
        $shadow = "<?php /* blade:1 */ ?>content\n";
        $bladeSource = "content\n{{-- @psalm-suppress Foo --}}\n";

        $this->assertSame([], (new SuppressionInjector())->resolve($shadow, $bladeSource, [1 => 1]));
    }

    #[Test]
    public function never_targets_the_markers_own_open_tag(): void
    {
        // The marker itself opens with `<?php`; the negative lookahead in the
        // injector must skip past it to the REAL statement's open tag.
        $shadow = "<?php /* blade:2 */ ?><?php echo e(\$foo); ?>\n";
        $bladeSource = "{{-- @psalm-suppress Foo --}}\n{{ \$foo }}\n";
        $lineMap = [1 => 2];

        $result = (new SuppressionInjector())->inject($shadow, $bladeSource, $lineMap);

        $this->assertStringNotContainsString('blade:2 */ /** @psalm-suppress', $result);
        $this->assertStringContainsString('<?php /** @psalm-suppress Foo */ echo e($foo); ?>', $result);
    }
}
