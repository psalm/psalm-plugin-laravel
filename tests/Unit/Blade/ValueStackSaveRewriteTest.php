<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\ValueStackSaveRewrite;

#[CoversClass(ValueStackSaveRewrite::class)]
final class ValueStackSaveRewriteTest extends TestCase
{
    private const REWRITTEN_CONDITION = "if (\\array_key_exists('value', \\get_defined_vars())) {";

    /** `CompilesSessions.php` / `CompilesContexts.php` start-of-block output, verified against a real compile. */
    private function save(string $directive): string
    {
        return "<?php \$__{$directive}Args = ['k'];\n"
            . "if ({$directive}()->has(\$__{$directive}Args[0])) :\n"
            . "if (isset(\$value)) { \$__{$directive}Previous[] = \$value; }\n"
            . "\$value = {$directive}()->get(\$__{$directive}Args[0]); ?>";
    }

    /** @return iterable<string, array{string}> */
    public static function directives(): iterable
    {
        yield 'session' => ['session'];
        yield 'context' => ['context'];
    }

    #[Test]
    #[DataProvider('directives')]
    public function only_the_save_condition_is_rewritten_on_the_same_line(string $directive): void
    {
        $compiled = $this->save($directive);

        $applied = ValueStackSaveRewrite::apply($compiled);

        $this->assertSame(
            \str_replace('if (isset($value)) {', self::REWRITTEN_CONDITION, $compiled),
            $applied,
        );
        $this->assertSame(\substr_count($compiled, "\n"), \substr_count($applied, "\n"));
    }

    #[Test]
    public function both_directives_are_rewritten_in_one_template(): void
    {
        $applied = ValueStackSaveRewrite::apply($this->save('session') . "\ntext\n" . $this->save('context'));

        $this->assertSame(2, \substr_count($applied, self::REWRITTEN_CONDITION));
        $this->assertStringNotContainsString('isset($value)', $applied);
    }

    #[Test]
    #[DataProvider('directives')]
    public function a_copy_inside_a_php_comment_is_untouched(string $directive): void
    {
        $compiled = "<?php /*\n" . \substr($this->save($directive), \strlen('<?php ')) . "\n*/ ?>";

        $this->assertSame($compiled, ValueStackSaveRewrite::apply($compiled));
    }

    #[Test]
    public function a_mismatched_directive_name_is_untouched(): void
    {
        $compiled = \str_replace('$__sessionPrevious', '$__contextPrevious', $this->save('session'));

        $this->assertSame($compiled, ValueStackSaveRewrite::apply($compiled));
    }

    #[Test]
    public function an_unrelated_isset_of_value_is_untouched(): void
    {
        $compiled = "<?php if (isset(\$value)) { \$__sessionPrevious[] = \$value; } ?>\n<?php if (isset(\$value)): ?>\n";

        $this->assertSame($compiled, ValueStackSaveRewrite::apply($compiled));
    }

    #[Test]
    #[DataProvider('directives')]
    public function a_template_that_unsets_value_is_untouched(string $directive): void
    {
        $compiled = "<?php unset(\$a, \$value); ?>\n" . $this->save($directive);

        $this->assertSame($compiled, ValueStackSaveRewrite::apply($compiled));
    }

    #[Test]
    public function an_unset_of_another_variable_does_not_block_the_rewrite(): void
    {
        $applied = ValueStackSaveRewrite::apply('<?php unset($valueOther, $values); ?>' . "\n" . $this->save('session'));

        $this->assertStringContainsString(self::REWRITTEN_CONDITION, $applied);
    }

    #[Test]
    #[DataProvider('directives')]
    public function the_compilers_own_end_of_block_unset_does_not_block_the_rewrite(string $directive): void
    {
        $end = "<?php unset(\$value);\n"
            . "if (isset(\$__{$directive}Previous) && !empty(\$__{$directive}Previous)) { \$value = array_pop(\$__{$directive}Previous); }\n"
            . "if (isset(\$__{$directive}Previous) && empty(\$__{$directive}Previous)) { unset(\$__{$directive}Previous); }\n"
            . "endif;\n"
            . "unset(\$__{$directive}Args); ?>\n";

        $applied = ValueStackSaveRewrite::apply($this->save($directive) . $end . $this->save($directive) . $end);

        $this->assertSame(2, \substr_count($applied, self::REWRITTEN_CONDITION));
    }
}
