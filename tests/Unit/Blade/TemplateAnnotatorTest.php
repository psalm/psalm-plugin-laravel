<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\Annotate\TemplateAnnotator;

#[CoversClass(TemplateAnnotator::class)]
final class TemplateAnnotatorTest extends TestCase
{
    private const NEW_TITLE_BLOCK = "<?php\n/**\n * @var string \$title\n */\n?>\n";

    #[Test]
    public function inserts_a_php_block_at_the_top_of_a_template_that_has_none(): void
    {
        $source = "<h1>{{ \$title }}</h1>\n";

        $result = TemplateAnnotator::annotate($source, ['title' => 'string']);

        $this->assertNotNull($result);
        $this->assertSame(self::NEW_TITLE_BLOCK . "<h1>{{ \$title }}</h1>\n", $result);
    }

    #[Test]
    public function appends_to_the_header_docblock_before_its_closing_delimiter(): void
    {
        $source = "<?php\n\ndeclare(strict_types=1);\n\n/**\n * Header.\n *\n * @var int \$count\n */\n?>\n<p>{{ \$count }} {{ \$title }}</p>\n";

        $result = TemplateAnnotator::annotate($source, ['title' => 'string', 'count' => 'int']);

        $this->assertNotNull($result);
        $this->assertSame(
            "<?php\n\ndeclare(strict_types=1);\n\n/**\n * Header.\n *\n * @var int \$count\n * @var string \$title\n */\n?>\n"
            . "<p>{{ \$count }} {{ \$title }}</p>\n",
            $result,
        );
    }

    #[Test]
    public function opens_out_a_one_line_header_docblock_into_one_tag_per_line(): void
    {
        $source = "<?php /** Header. */ ?>\n<p>{{ \$title }}</p>\n";

        $result = TemplateAnnotator::annotate($source, ['title' => 'string']);

        $this->assertNotNull($result);
        $this->assertSame("<?php /**\n * Header.\n * @var string \$title\n */ ?>\n<p>{{ \$title }}</p>\n", $result);
    }

    #[Test]
    public function opens_out_a_one_line_declaration_on_its_own_line_aligned_with_the_new_ones(): void
    {
        $source = "<?php declare(strict_types=1);\n/** @var \\App\\Course \$course */\n?>\n{{ \$course }}{{ \$report }}\n";

        $result = TemplateAnnotator::annotate($source, ['report' => '\\App\\Report']);

        $this->assertNotNull($result);
        $this->assertSame(
            "<?php declare(strict_types=1);\n/**\n * @var \\App\\Course \$course\n * @var \\App\\Report \$report\n */\n?>\n{{ \$course }}{{ \$report }}\n",
            $result,
        );
    }

    #[Test]
    public function never_appends_into_a_docblock_of_a_function_or_closure_or_one_nested_in_braces(): void
    {
        foreach (
            [
                "<?php\n/** Does a thing. */\nfunction f() {}\n?>\n",
                "<?php\n\$f = /** Closure. */ fn () => 1;\n?>\n",
                "<?php\nif (true) {\n    /** Nested. */\n    \$a = 1;\n}\n?>\n",
                "<?php\n/** Class. */\nfinal class A {}\n?>\n",
            ] as $header
        ) {
            $result = TemplateAnnotator::annotate($header . "<p>{{ \$title }}</p>\n", ['title' => 'string']);

            $this->assertNotNull($result);
            $this->assertSame($header . self::NEW_TITLE_BLOCK . "<p>{{ \$title }}</p>\n", $result, $header);
        }
    }

    #[Test]
    public function adds_a_block_after_the_leading_php_block_when_it_has_no_docblock(): void
    {
        $source = "<?php declare(strict_types=1); ?>\n<p>{{ \$title }}</p>\n";

        $result = TemplateAnnotator::annotate($source, ['title' => 'string']);

        $this->assertNotNull($result);
        $this->assertSame(
            "<?php declare(strict_types=1); ?>\n" . self::NEW_TITLE_BLOCK . "<p>{{ \$title }}</p>\n",
            $result,
            'the declare stays the first statement',
        );
    }

    #[Test]
    public function adds_a_docblock_after_the_open_tag_when_the_leading_php_block_never_closes(): void
    {
        $source = "<?php\ndeclare(strict_types=1);\n";

        $result = TemplateAnnotator::annotate($source, ['title' => 'string']);

        $this->assertNotNull($result);
        $this->assertSame("<?php\n/**\n * @var string \$title\n */\ndeclare(strict_types=1);\n", $result);
    }

    #[Test]
    public function is_idempotent_over_every_placement(): void
    {
        foreach (
            [
                "<p>{{ \$title }}</p>\n",
                "<?php /** Header. */ ?>\n<p>{{ \$title }}</p>\n",
                "<?php declare(strict_types=1); ?>\n<p>{{ \$title }}</p>\n",
                "<?php\n/**\n * Header.\n */\n?>\n<p>{{ \$title }}</p>\n",
            ] as $source
        ) {
            $first = TemplateAnnotator::annotate($source, ['title' => 'string']);

            $this->assertNotNull($first);
            $this->assertNull(TemplateAnnotator::annotate($first, ['title' => 'string']), $source);
        }
    }

    #[Test]
    public function skips_a_name_already_declared_in_a_raw_php_docblock(): void
    {
        $source = "<?php /** @var \\App\\Models\\User \$user */ ?>\n<h1>{{ \$user->name }}</h1>\n";

        $this->assertNull(TemplateAnnotator::annotate($source, ['user' => 'App\\Models\\User']));
    }

    #[Test]
    public function an_existing_equals_null_declaration_is_already_declared(): void
    {
        $source = "<?php\n/**\n * @var string|null \$a = null The a\n */\n?>\n<p>{{ \$a }}</p>\n";

        $this->assertNull(TemplateAnnotator::annotate($source, ['a' => 'string'], ['a' => true]));
    }

    #[Test]
    public function writes_an_optional_name_as_nullable_with_an_equals_null_tail(): void
    {
        $result = TemplateAnnotator::annotate("<p>x</p>\n", ['a' => 'string', 'b' => 'int|null', 'c' => 'int'], ['a' => true, 'b' => true]);

        $this->assertNotNull($result);
        $this->assertSame("<?php\n/**\n * @var string|null \$a = null\n * @var int|null \$b = null\n * @var int \$c\n */\n?>\n<p>x</p>\n", $result);
    }

    #[Test]
    public function skips_a_name_already_declared_in_an_at_php_block(): void
    {
        $source = "@php\n/** @var \\App\\Models\\User \$user */\n@endphp\n<h1>{{ \$user->name }}</h1>\n";

        $this->assertNull(TemplateAnnotator::annotate($source, ['user' => 'App\\Models\\User']));
    }

    #[Test]
    public function preserves_the_dominant_crlf_line_ending(): void
    {
        $source = "<h1>{{ \$title }}</h1>\r\n<p>{{ \$body }}</p>\r\n";

        $result = TemplateAnnotator::annotate($source, ['title' => 'string']);

        $this->assertNotNull($result);
        $this->assertSame("<?php\r\n/**\r\n * @var string \$title\r\n */\r\n?>\r\n" . $source, $result);
    }

    #[Test]
    public function inserts_after_a_utf8_bom_rather_than_before_it(): void
    {
        $source = "\u{FEFF}<h1>{{ \$title }}</h1>\n";

        $result = TemplateAnnotator::annotate($source, ['title' => 'string']);

        $this->assertNotNull($result);
        $this->assertSame("\u{FEFF}" . self::NEW_TITLE_BLOCK . "<h1>{{ \$title }}</h1>\n", $result);
    }

    #[Test]
    public function recognises_a_declaration_followed_by_a_description_naming_another_variable(): void
    {
        $source = "<?php /** @var string \$title The title above \$page */ ?>\n<p>x</p>\n";

        $this->assertNull(TemplateAnnotator::annotate($source, ['title' => 'string']));
        $this->assertNotNull(TemplateAnnotator::annotate($source, ['page' => 'string']), '`$page` is description, not a declaration');
    }

    #[Test]
    public function reads_a_raw_php_declaration_without_being_fooled_by_the_code_after_it(): void
    {
        // The scan used to run to the end of the line, binding `$body` (the echo) and missing the
        // `$title` the docblock actually declares.
        $source = "<?php /** @var string \$title */ echo \$body; ?>\n<p>x</p>\n";

        $result = TemplateAnnotator::annotate($source, ['title' => 'string', 'body' => 'string']);

        $this->assertNotNull($result);
        $this->assertSame("<?php /**\n * @var string \$title\n * @var string \$body\n */ echo \$body; ?>\n<p>x</p>\n", $result);
    }

    #[Test]
    public function does_not_read_a_var_that_only_appears_inside_a_php_string(): void
    {
        $source = "<?php echo '@var string \$user'; ?>\n<p>x</p>\n";

        $result = TemplateAnnotator::annotate($source, ['user' => 'string']);

        $this->assertNotNull($result, 'a string literal is not a declaration');
        $this->assertSame("<?php echo '@var string \$user'; ?>\n<?php\n/**\n * @var string \$user\n */\n?>\n<p>x</p>\n", $result);
    }

    #[Test]
    public function ignores_a_declaration_inside_a_verbatim_block_or_a_blade_comment(): void
    {
        $source = "@verbatim\n<?php /** @var \\App\\User \$user */ ?>\n@endverbatim\n{{-- <?php /** @var \\App\\User \$user */ ?> --}}\n<p>x</p>\n";

        $result = TemplateAnnotator::annotate($source, ['user' => 'App\\User']);

        $this->assertNotNull($result, 'a verbatim body and a Blade comment are literal text, not PHP');
        $this->assertSame("<?php\n/**\n * @var App\\User \$user\n */\n?>\n" . $source, $result);
    }

    #[Test]
    public function an_expression_local_docblock_is_not_the_header(): void
    {
        $header = "<?php \$items = [/** Per-item note. */ 1]; ?>\n";
        $result = TemplateAnnotator::annotate($header . "<p>{{ \$title }}</p>\n", ['title' => 'string']);

        $this->assertNotNull($result);
        $this->assertSame($header . self::NEW_TITLE_BLOCK . "<p>{{ \$title }}</p>\n", $result, 'a new block after the leading one, not inside its array item');
    }
}
