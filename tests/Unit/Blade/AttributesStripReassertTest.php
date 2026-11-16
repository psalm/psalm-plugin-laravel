<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\AttributesStripReassert;

#[CoversClass(AttributesStripReassert::class)]
final class AttributesStripReassertTest extends TestCase
{
    private const REASSERT = ' /** @var \Illuminate\View\ComponentAttributeBag $attributes */ ?>';

    /** `CompilesComponents.php:99-102`'s restore, verified against a real compile of `nested-attributes.blade.php`. */
    private const RESTORE = "<?php if (isset(\$__attributesOriginal5194778a3a7b899dcee5619d0610f5cf)): ?>\n"
        . "<?php \$attributes = \$__attributesOriginal5194778a3a7b899dcee5619d0610f5cf; ?>\n"
        . "<?php unset(\$__attributesOriginal5194778a3a7b899dcee5619d0610f5cf); ?>\n"
        . '<?php endif; ?>';

    /** `ComponentTagCompiler.php:262-264`'s inner strip, same real compile. */
    private const STRIP = "<?php if (isset(\$attributes) && \$attributes instanceof Illuminate\\View\\ComponentAttributeBag): ?>\n"
        . "<?php \$attributes = \$attributes->except(\\Illuminate\\View\\AnonymousComponent::ignoredParameterNames()); ?>\n"
        . '<?php endif; ?>';

    #[Test]
    public function the_strip_endif_gets_the_reassert_on_the_same_line(): void
    {
        $applied = AttributesStripReassert::apply(self::STRIP);

        $this->assertSame(\str_replace('endif; ?>', 'endif;' . self::REASSERT, self::STRIP), $applied);
        $this->assertSame(\substr_count(self::STRIP, "\n"), \substr_count($applied, "\n"));
    }

    /** Psalm 7.7 keeps `$attributes` non-null past the restore, so a re-assert there is an UnnecessaryVarAnnotation. */
    #[Test]
    public function the_restore_endif_is_not_reasserted(): void
    {
        $compiled = self::STRIP . "\nnested\n" . self::RESTORE;

        $applied = AttributesStripReassert::apply($compiled);

        $this->assertSame(1, \substr_count($applied, self::REASSERT));
        $this->assertStringEndsWith(self::RESTORE, $applied);
    }

    /** #1543 trap 1: the docblock must land AFTER `endif;`, never inside the strip's own `if` branch. */
    #[Test]
    public function the_reassert_lands_after_endif_not_inside_the_if_branch(): void
    {
        $applied = AttributesStripReassert::apply(self::STRIP);

        $this->assertStringNotContainsString('ignoredParameterNames()); /**', $applied);
    }

    /** An unrelated `endif;` (no author code path resembles the compiled strip shape) is untouched. */
    #[Test]
    public function an_unrelated_endif_is_not_matched(): void
    {
        $source = "<?php if (\$cond): ?>\nyes\n<?php endif; ?>\n";

        $this->assertSame($source, AttributesStripReassert::apply($source));
    }

    /**
     * #1543 external review finding 1: a regex-only match cannot tell compiler output apart from an
     * author's own PHP comment whose TEXT happens to spell out the strip shape (a realistic
     * accident: pasting compiled output into a debugging comment). The injected suffix's own comment
     * closer, followed by `?>`, would end that comment AND php mode early, turning every following
     * template line into raw HTML — proven by a live `strlen([])` call right after the comment.
     */
    #[Test]
    public function a_strip_shaped_block_inside_a_php_comment_is_not_reasserted(): void
    {
        $compiled = "<?php\n/*\n" . self::STRIP . "\n*/\nstrlen([]);\n?>";

        $this->assertSame($compiled, AttributesStripReassert::apply($compiled));
    }

    /**
     * #1543 external review finding 2: the strip `endif` only proves LARAVEL'S OWN bookkeeping left
     * `$attributes` non-null — never that an author's own reassignment inside the same view didn't
     * null it out again first. `templateAssignsAttributes()` scans the raw TEMPLATE source (not the
     * compiled shadow) for that; {@see \Psalm\LaravelPlugin\Blade\ShadowCompiler} skips the whole
     * re-assert for a template it flags.
     */
    #[Test]
    public function template_assigns_attributes_detects_a_plain_reassignment(): void
    {
        $this->assertTrue(AttributesStripReassert::templateAssignsAttributes(
            "@props([])\n@php \$attributes = \$flag ? null : \$attributes; @endphp\n<x-alert />\n",
        ));
    }

    /** `??=`/`.=`/`==` are not plain assignment: they never hand `$attributes` an unrelated value wholesale. */
    #[Test]
    public function template_assigns_attributes_ignores_compound_and_comparison_operators(): void
    {
        $this->assertFalse(AttributesStripReassert::templateAssignsAttributes(
            "@props([])\n@php \$attributes ??= new \\Illuminate\\View\\ComponentAttributeBag; @endphp\n<x-alert />\n",
        ));
        $this->assertFalse(AttributesStripReassert::templateAssignsAttributes(
            "@if (\$attributes == null)\nyes\n@endif\n",
        ));
    }
}
