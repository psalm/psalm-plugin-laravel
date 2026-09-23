<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\AttributesRestoreReassert;

#[CoversClass(AttributesRestoreReassert::class)]
final class AttributesRestoreReassertTest extends TestCase
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
    public function the_restore_endif_gets_the_reassert_on_the_same_line(): void
    {
        $applied = AttributesRestoreReassert::apply(self::RESTORE);

        $this->assertSame(\str_replace('endif; ?>', 'endif;' . self::REASSERT, self::RESTORE), $applied);
        $this->assertSame(\substr_count(self::RESTORE, "\n"), \substr_count($applied, "\n"));
    }

    #[Test]
    public function the_strip_endif_gets_the_reassert_on_the_same_line(): void
    {
        $applied = AttributesRestoreReassert::apply(self::STRIP);

        $this->assertSame(\str_replace('endif; ?>', 'endif;' . self::REASSERT, self::STRIP), $applied);
        $this->assertSame(\substr_count(self::STRIP, "\n"), \substr_count($applied, "\n"));
    }

    /** #1543 trap 3: fixing only the restore leaves a read inside the tag's own body red. */
    #[Test]
    public function both_blocks_are_reasserted_when_present_together(): void
    {
        $compiled = self::STRIP . "\nnested\n" . self::RESTORE;

        $applied = AttributesRestoreReassert::apply($compiled);

        $this->assertSame(2, \substr_count($applied, self::REASSERT));
    }

    /** A class-based component's `ignoredParameterNames()` call names its own FQCN, not `AnonymousComponent`. */
    #[Test]
    public function the_strip_block_matches_a_class_based_components_ignored_parameter_names_call(): void
    {
        $strip = "<?php if (isset(\$attributes) && \$attributes instanceof Illuminate\\View\\ComponentAttributeBag): ?>\n"
            . "<?php \$attributes = \$attributes->except(\\App\\View\\Components\\Alert::ignoredParameterNames()); ?>\n"
            . '<?php endif; ?>';

        $this->assertStringContainsString(self::REASSERT, AttributesRestoreReassert::apply($strip));
    }

    /** #1543 trap 1: the docblock must land AFTER `endif;`, never inside the restore's own `if` branch. */
    #[Test]
    public function the_reassert_lands_after_endif_not_inside_the_if_branch(): void
    {
        $applied = AttributesRestoreReassert::apply(self::RESTORE);

        // The else-arm's null survives past THIS point if the docblock lands any earlier.
        $this->assertStringNotContainsString('$attributes = $__attributesOriginal5194778a3a7b899dcee5619d0610f5cf; /**', $applied);
        $this->assertStringNotContainsString('unset($__attributesOriginal5194778a3a7b899dcee5619d0610f5cf); /**', $applied);
    }

    /** An unrelated `endif;` (no author code path resembles the compiled restore/strip shape) is untouched. */
    #[Test]
    public function an_unrelated_endif_is_not_matched(): void
    {
        $source = "<?php if (\$cond): ?>\nyes\n<?php endif; ?>\n";

        $this->assertSame($source, AttributesRestoreReassert::apply($source));
    }

    #[Test]
    public function content_with_neither_block_is_returned_unchanged(): void
    {
        $source = "<?php echo e(\$name); ?>\n";

        $this->assertSame($source, AttributesRestoreReassert::apply($source));
    }
}
