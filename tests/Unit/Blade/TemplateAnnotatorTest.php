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

    /** Leading PHP blocks whose docblock Psalm does not apply to the body, one closing tag each. */
    private const NON_HEADER_BLOCKS = [
        "<?php\n/**\n * Header.\n */\nuse Foo\\Bar;\n?>",
        "<?php\n/**\n * Header.\n */\nnamespace Foo;\n?>",
        '<?php $f = function (): int { /** @var int $n */ $n = 1; return $n; }; ?>',
        '<?php function helper(): int { /** @var int $n */ $n = 1; return $n; } ?>',
        '<?php $x = strlen(/** @var string $b */ "b"); ?>',
        '<?php $x = /** @var string $b */ "b"; ?>',
        '<?php $a = [/** @var int $i */ 1]; ?>',
        '<?php function f() { $a = 1; $s = "{$a}}"; /** @var int $n */ $n = 1; } ?>',
    ];

    #[Test]
    public function inserts_a_php_block_at_the_top_of_a_template_that_has_none(): void
    {
        $source = "<h1>{{ \$title }}</h1>\n";

        $result = TemplateAnnotator::annotate($source, ['title' => 'string']);

        $this->assertNotNull($result);
        $this->assertSame(self::NEW_TITLE_BLOCK . "<h1>{{ \$title }}</h1>\n", $result[0]);
        $this->assertSame(3, $result[1], 'the declaration is the third line: after `<?php` and `/**`');
        $this->assertSame(['@var string $title'], $result[2]);
    }

    #[Test]
    public function leaves_an_existing_blade_comment_declaration_untouched(): void
    {
        $source = "{{-- @var \\App\\Models\\User \$user --}}\n<h1>{{ \$user->name }} {{ \$title }}</h1>\n";

        $result = TemplateAnnotator::annotate($source, ['title' => 'string']);

        $this->assertNotNull($result);
        $this->assertSame(self::NEW_TITLE_BLOCK . $source, $result[0]);
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
            $result[0],
        );
        $this->assertSame(9, $result[1]);
        $this->assertSame(['@var string $title'], $result[2]);
    }

    #[Test]
    public function matches_the_indentation_and_crlf_endings_of_the_header_docblock(): void
    {
        $source = "<?php\r\n\t/**\r\n\t * Header.\r\n\t */\r\n?>\r\n<p>{{ \$title }}</p>\r\n";

        $result = TemplateAnnotator::annotate($source, ['title' => 'string']);

        $this->assertNotNull($result);
        $this->assertSame(
            "<?php\r\n\t/**\r\n\t * Header.\r\n\t * @var string \$title\r\n\t */\r\n?>\r\n<p>{{ \$title }}</p>\r\n",
            $result[0],
        );
    }

    #[Test]
    public function opens_out_a_one_line_header_docblock_keeping_its_own_bytes(): void
    {
        $source = "<?php /** Header. */ ?>\n<p>{{ \$title }}</p>\n";

        $result = TemplateAnnotator::annotate($source, ['title' => 'string']);

        $this->assertNotNull($result);
        $this->assertSame("<?php /** Header. \n * @var string \$title\n */ ?>\n<p>{{ \$title }}</p>\n", $result[0]);
        $this->assertSame(2, $result[1]);
    }

    #[Test]
    public function adds_a_block_after_the_leading_php_block_when_it_has_no_docblock(): void
    {
        $source = "<?php declare(strict_types=1); ?>\n<p>{{ \$title }}</p>\n";

        $result = TemplateAnnotator::annotate($source, ['title' => 'string']);

        $this->assertNotNull($result);
        $this->assertSame(
            "<?php declare(strict_types=1); ?>\n" . self::NEW_TITLE_BLOCK . "<p>{{ \$title }}</p>\n",
            $result[0],
            'the declare stays the first statement',
        );
        $this->assertSame(4, $result[1]);
    }

    #[Test]
    public function a_non_doc_comment_in_the_leading_php_block_does_not_count_as_a_docblock(): void
    {
        $source = "<?php\n// declare(strict_types=1);\n/* not a docblock */\ndeclare(strict_types=1);\n?>\n<p>{{ \$title }}</p>\n";

        $result = TemplateAnnotator::annotate($source, ['title' => 'string']);

        $this->assertNotNull($result);
        $this->assertSame(
            "<?php\n// declare(strict_types=1);\n/* not a docblock */\ndeclare(strict_types=1);\n?>\n"
            . self::NEW_TITLE_BLOCK . "<p>{{ \$title }}</p>\n",
            $result[0],
        );
    }

    #[Test]
    public function adds_a_docblock_after_the_open_tag_when_the_leading_php_block_never_closes(): void
    {
        $source = "<?php\ndeclare(strict_types=1);\n";

        $result = TemplateAnnotator::annotate($source, ['title' => 'string']);

        $this->assertNotNull($result);
        $this->assertSame("<?php\n/**\n * @var string \$title\n */\ndeclare(strict_types=1);\n", $result[0]);
    }

    #[Test]
    public function appends_to_the_docblock_the_statement_carries_when_two_docblocks_stack(): void
    {
        // The PHP parser attaches only the last doc comment to a statement, so lines appended to the
        // first of two would be dropped and the declarations would not type the body.
        $source = "<?php\n/** @var Site \$site */\n\n/** @var list<string> \$times */\n\$times = [];\n?>\n<p>{{ \$title }}</p>\n";

        $result = TemplateAnnotator::annotate($source, ['title' => 'string']);

        $this->assertNotNull($result);
        $this->assertSame(
            "<?php\n/** @var Site \$site */\n\n/** @var list<string> \$times \n * @var string \$title\n */\n\$times = [];\n?>\n<p>{{ \$title }}</p>\n",
            $result[0],
        );
        $this->assertSame(5, $result[1]);
    }

    #[Test]
    public function a_docblock_on_a_function_is_not_a_header(): void
    {
        $source = "<?php /** Helper. */ function helper(): void {} ?>\n<p>{{ \$title }}</p>\n";

        $result = TemplateAnnotator::annotate($source, ['title' => 'string']);

        $this->assertNotNull($result);
        $this->assertSame(
            "<?php /** Helper. */ function helper(): void {} ?>\n" . self::NEW_TITLE_BLOCK . "<p>{{ \$title }}</p>\n",
            $result[0],
        );
    }

    #[Test]
    public function a_plain_comment_between_stacked_docblocks_does_not_commit_the_first(): void
    {
        foreach (['// note', '/* note */'] as $comment) {
            $source = "<?php\n/** @var Site \$site */\n{$comment}\n/** @var list<string> \$times */\n\$times = [];\n?>\n<p>{{ \$title }}</p>\n";

            $result = TemplateAnnotator::annotate($source, ['title' => 'string']);

            $this->assertNotNull($result);
            $this->assertSame(
                "<?php\n/** @var Site \$site */\n{$comment}\n/** @var list<string> \$times \n * @var string \$title\n */\n\$times = [];\n?>\n<p>{{ \$title }}</p>\n",
                $result[0],
                $comment,
            );
        }
    }

    #[Test]
    public function a_docblock_that_does_not_reach_a_statement_is_not_a_header(): void
    {
        foreach (self::NON_HEADER_BLOCKS as $block) {
            $source = $block . "\n<p>{{ \$title }}</p>\n";

            $result = TemplateAnnotator::annotate($source, ['title' => 'string']);

            $this->assertNotNull($result);
            $this->assertSame($block . "\n" . self::NEW_TITLE_BLOCK . "<p>{{ \$title }}</p>\n", $result[0], $block);
        }
    }

    #[Test]
    public function does_not_mistake_php_looking_text_for_a_header(): void
    {
        $source = "<p>/** not php */ {{ \$title }}</p>\n";

        $result = TemplateAnnotator::annotate($source, ['title' => 'string']);

        $this->assertNotNull($result);
        $this->assertSame(self::NEW_TITLE_BLOCK . $source, $result[0]);
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
                "<?php\n/** @var Site \$site */\n\n/** @var list<string> \$times */\n\$times = [];\n?>\n<p>{{ \$title }}</p>\n",
                "<?php /** Helper. */ function helper(): void {} ?>\n<p>{{ \$title }}</p>\n",
                "<?php\n/** @var Site \$site */\n// note\n/** @var list<string> \$times */\n\$times = [];\n?>\n<p>{{ \$title }}</p>\n",
                "\u{FEFF}<?php\r\ndeclare(strict_types=1);\r\n?>\r\n<p>{{ \$title }}</p>\r\n",
            ] as $source
        ) {
            $first = TemplateAnnotator::annotate($source, ['title' => 'string']);

            $this->assertNotNull($first);
            $this->assertNull(TemplateAnnotator::annotate($first[0], ['title' => 'string']), $source);
        }

        foreach (self::NON_HEADER_BLOCKS as $block) {
            $first = TemplateAnnotator::annotate($block . "\n<p>{{ \$title }}</p>\n", ['title' => 'string']);

            $this->assertNotNull($first);
            $this->assertNull(TemplateAnnotator::annotate($first[0], ['title' => 'string']), $block);
        }
    }

    #[Test]
    public function declines_when_every_name_is_already_declared(): void
    {
        $source = "{{-- @var string \$title --}}\n<h1>{{ \$title }}</h1>\n";

        $this->assertNull(TemplateAnnotator::annotate($source, ['title' => 'int']));
    }

    #[Test]
    public function is_idempotent_across_a_second_run(): void
    {
        $source = "<h1>{{ \$title }}</h1>\n";
        $vars = ['title' => 'string'];

        $first = TemplateAnnotator::annotate($source, $vars);

        $this->assertNotNull($first);
        $this->assertNull(TemplateAnnotator::annotate($first[0], $vars));
    }

    #[Test]
    public function skips_a_name_already_declared_in_a_raw_php_docblock(): void
    {
        $source = "<?php /** @var \\App\\Models\\User \$user */ ?>\n<h1>{{ \$user->name }}</h1>\n";

        $this->assertNull(TemplateAnnotator::annotate($source, ['user' => 'App\\Models\\User']));
    }

    #[Test]
    public function preserves_the_dominant_crlf_line_ending(): void
    {
        $source = "<h1>{{ \$title }}</h1>\r\n<p>{{ \$body }}</p>\r\n";

        $result = TemplateAnnotator::annotate($source, ['title' => 'string']);

        $this->assertNotNull($result);
        $this->assertSame("<?php\r\n/**\r\n * @var string \$title\r\n */\r\n?>\r\n" . $source, $result[0]);
    }

    #[Test]
    public function inserts_after_a_utf8_bom_rather_than_before_it(): void
    {
        $source = "\u{FEFF}<h1>{{ \$title }}</h1>\n";

        $result = TemplateAnnotator::annotate($source, ['title' => 'string']);

        $this->assertNotNull($result);
        $this->assertSame("\u{FEFF}" . self::NEW_TITLE_BLOCK . "<h1>{{ \$title }}</h1>\n", $result[0]);
    }

    #[Test]
    public function appends_to_a_header_docblock_that_follows_a_bom(): void
    {
        $source = "\u{FEFF}<?php\n/**\n * Header.\n */\n?>\n<p>{{ \$title }}</p>\n";

        $result = TemplateAnnotator::annotate($source, ['title' => 'string']);

        $this->assertNotNull($result);
        $this->assertSame(
            "\u{FEFF}<?php\n/**\n * Header.\n * @var string \$title\n */\n?>\n<p>{{ \$title }}</p>\n",
            $result[0],
        );
    }

    #[Test]
    public function emits_several_names_in_a_stable_alphabetical_order(): void
    {
        $result = TemplateAnnotator::annotate("<p>x</p>\n", ['title' => 'string', 'count' => 'int']);

        $this->assertNotNull($result);
        $this->assertSame(
            "<?php\n/**\n * @var int \$count\n * @var string \$title\n */\n?>\n<p>x</p>\n",
            $result[0],
        );
    }

    #[Test]
    public function recognises_a_declaration_whose_type_itself_contains_a_variable(): void
    {
        // The parser binds the LAST $name in the comment ($callback); a reader that bound the first
        // one ($f) would see the name as undeclared and append a duplicate declaration for it.
        $source = "{{-- @var Closure(Foo \$f): Bar \$callback --}}\n<p>x</p>\n";

        $this->assertNull(TemplateAnnotator::annotate($source, ['callback' => 'mixed']));
    }

    #[Test]
    public function recognises_a_raw_php_declaration_whose_type_contains_a_variable(): void
    {
        $source = "<?php /** @var Closure(Foo \$f): Bar \$callback */ ?>\n<p>x</p>\n";

        $this->assertNull(TemplateAnnotator::annotate($source, ['callback' => 'mixed']));
    }

    #[Test]
    public function is_idempotent_for_a_type_it_wrote_that_contains_a_variable(): void
    {
        $vars = ['cb' => 'Closure(string $a):void'];

        $first = TemplateAnnotator::annotate("<p>x</p>\n", $vars);

        $this->assertNotNull($first);
        $this->assertNull(TemplateAnnotator::annotate($first[0], $vars), 'the comment must not be re-appended');
    }

    #[Test]
    public function recognises_a_declaration_with_a_non_ascii_variable_name(): void
    {
        // PHP identifiers take bytes >= 0x80; a declaration the writer cannot read back is one it
        // appends again on every run.
        $source = "{{-- @var string \$men\u{00fc} --}}\n<p>x</p>\n";

        $this->assertNull(TemplateAnnotator::annotate($source, ["men\u{00fc}" => 'string']));
    }

    #[Test]
    public function is_idempotent_for_a_non_ascii_variable_name_it_wrote(): void
    {
        $vars = ["men\u{00fc}" => 'string'];

        $first = TemplateAnnotator::annotate("<p>x</p>\n", $vars);

        $this->assertNotNull($first);
        $this->assertNull(TemplateAnnotator::annotate($first[0], $vars));
    }

    #[Test]
    public function reads_a_raw_php_declaration_without_being_fooled_by_the_code_after_it(): void
    {
        // The scan used to run to the end of the line, binding `$body` (the echo) and missing the
        // `$title` the docblock actually declares.
        $source = "<?php /** @var string \$title */ echo \$body; ?>\n<p>x</p>\n";

        $result = TemplateAnnotator::annotate($source, ['title' => 'string', 'body' => 'string']);

        $this->assertNotNull($result);
        $this->assertSame(['@var string $body'], $result[2]);
    }

    #[Test]
    public function recognises_a_raw_php_declaration_with_a_non_ascii_variable_name(): void
    {
        // A reader binding only the ASCII prefix sees `$men` declared and `$menü` undeclared, and
        // appends a second declaration for the same variable on every run.
        $source = "<?php /** @var string \$men\u{00fc} */ ?>\n<p>x</p>\n";

        $this->assertNull(TemplateAnnotator::annotate($source, ["men\u{00fc}" => 'string']));
    }

    #[Test]
    public function does_not_read_a_var_that_only_appears_inside_a_php_string(): void
    {
        $source = "<?php echo '@var string \$user'; ?>\n<p>x</p>\n";

        $result = TemplateAnnotator::annotate($source, ['user' => 'string']);

        $this->assertNotNull($result, 'a string literal is not a declaration');
        $this->assertSame(['@var string $user'], $result[2]);
    }

    #[Test]
    public function ignores_a_declaration_inside_a_verbatim_block(): void
    {
        $source = "@verbatim\n{{-- @var \\App\\User \$user --}}\n@endverbatim\n<p>x</p>\n";

        $result = TemplateAnnotator::annotate($source, ['user' => 'App\\User']);

        $this->assertNotNull($result, 'a verbatim body is literal text, not a contract');
        $this->assertSame(['@var App\\User $user'], $result[2]);
    }

    #[Test]
    public function ignores_a_declaration_inside_a_php_block(): void
    {
        $source = "@php\n{{-- @var \\App\\User \$user --}}\n@endphp\n<p>x</p>\n";

        $result = TemplateAnnotator::annotate($source, ['user' => 'App\\User']);

        $this->assertNotNull($result, 'an @php body is PHP source, not a contract');
        $this->assertSame(['@var App\\User $user'], $result[2]);
    }

    #[Test]
    public function leaves_live_comments_alone_and_ignores_one_inside_a_verbatim_block(): void
    {
        $source = "{{-- @var A \$a --}}\n<p>x</p>\n@verbatim\n{{-- @var B \$b --}}\n@endverbatim\n";

        $result = TemplateAnnotator::annotate($source, ['a' => 'A', 'c' => 'C']);

        $this->assertNotNull($result);
        $this->assertSame(
            "<?php\n/**\n * @var C \$c\n */\n?>\n{{-- @var A \$a --}}\n<p>x</p>\n@verbatim\n{{-- @var B \$b --}}\n@endverbatim\n",
            $result[0],
        );
    }

    #[Test]
    public function ignores_a_declaration_nested_in_another_blade_comment(): void
    {
        $source = "{{-- note {{-- @var X \$x --}}\n<p>x</p>\n";

        $result = TemplateAnnotator::annotate($source, ['x' => 'X']);

        $this->assertNotNull($result, 'the parser reads one comment here, and its text is not a declaration');
        $this->assertSame("<?php\n/**\n * @var X \$x\n */\n?>\n{{-- note {{-- @var X \$x --}}\n<p>x</p>\n", $result[0]);
    }

    #[Test]
    public function does_not_slide_from_a_non_matching_live_comment_into_a_verbatim_body(): void
    {
        // `{{-- note --}}` is a live comment but not a declaration; an unanchored search from its
        // offset would continue into the verbatim body and read the dead `$x` declaration.
        $source = "{{-- note --}}\n@verbatim {{-- @var X \$x --}} @endverbatim\n";

        $this->assertNotNull(TemplateAnnotator::annotate($source, ['x' => 'X']));
    }
}
