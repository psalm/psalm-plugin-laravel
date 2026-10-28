<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\ClassLiteralCollector;

#[CoversClass(ClassLiteralCollector::class)]
final class ClassLiteralCollectorTest extends TestCase
{
    #[Test]
    #[TestWith(["<?php echo 'Vendor\\\\Package\\\\Generator'; ?>", 'Vendor\Package\Generator'], 'single-quoted FQCN')]
    public function a_literal_naming_a_namespaced_class_is_collected(string $php, string $expected): void
    {
        $this->assertSame([$expected], (new ClassLiteralCollector())->collectFromTokens(\token_get_all($php)));
    }

    #[Test]
    #[TestWith(["<?php echo '/\\\\d+/'; ?>"], 'regex literal')]
    #[TestWith(["<?php echo 'C:\\\\Users\\\\bob'; ?>", ], 'windows path, first segment fails the identifier rule')]
    #[TestWith(["<?php echo 'GlobalClass'; ?>"], 'no namespace separator at all, deliberately missed')]
    public function a_non_class_literal_is_not_collected(string $php): void
    {
        $this->assertSame([], (new ClassLiteralCollector())->collectFromTokens(\token_get_all($php)));
    }

    /**
     * Interpolated double-quoted strings tokenize as `T_ENCAPSED_AND_WHITESPACE`, never
     * `T_CONSTANT_ENCAPSED_STRING`: a class name embedded in one is not a literal and must not be a
     * candidate, whatever the surrounding text looks like.
     */
    #[Test]
    public function an_interpolated_string_is_not_collected(): void
    {
        $php = '<?php $x = 1; echo "Interp\\Name{$x}More"; ?>';

        $this->assertSame([], (new ClassLiteralCollector())->collectFromTokens(\token_get_all($php)));
    }

    /**
     * The prelude's ambient FQCNs live in stacked `@var` docblocks (`T_DOC_COMMENT`), which is
     * `PreludeBuilder`/`queueClassLikesForScanning()`'s territory (#1494), not this collector's.
     * Harvesting them here too would just duplicate that mechanism.
     */
    #[Test]
    public function a_docblock_fqcn_is_not_collected(): void
    {
        $php = "<?php\n/** @var \\Illuminate\\View\\Factory \$__env */\necho 1;\n?>";

        $this->assertSame([], (new ClassLiteralCollector())->collectFromTokens(\token_get_all($php)));
    }

    #[Test]
    public function duplicates_across_multiple_literals_are_deduped(): void
    {
        $php = "<?php echo 'Vendor\\\\Package\\\\Generator'; echo 'Vendor\\\\Package\\\\Generator'; ?>";

        $this->assertSame(['Vendor\Package\Generator'], (new ClassLiteralCollector())->collectFromTokens(\token_get_all($php)));
    }
}
