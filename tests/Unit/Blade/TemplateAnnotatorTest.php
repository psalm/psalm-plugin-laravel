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
    #[Test]
    public function inserts_a_contract_block_at_the_top_of_a_template_that_has_none(): void
    {
        $source = "<h1>{{ \$title }}</h1>\n";

        $result = TemplateAnnotator::annotate($source, ['title' => 'string']);

        $this->assertNotNull($result);
        $this->assertSame("{{-- @var string \$title --}}\n<h1>{{ \$title }}</h1>\n", $result[0]);
        $this->assertSame(1, $result[1]);
    }

    #[Test]
    public function appends_after_an_existing_contract_block_leaving_it_byte_identical(): void
    {
        $source = "{{-- @var \\App\\Models\\User \$user --}}\n<h1>{{ \$user->name }} {{ \$title }}</h1>\n";

        $result = TemplateAnnotator::annotate($source, ['title' => 'string']);

        $this->assertNotNull($result);
        $this->assertSame(
            "{{-- @var \\App\\Models\\User \$user --}}\n"
            . "{{-- @var string \$title --}}\n"
            . "<h1>{{ \$user->name }} {{ \$title }}</h1>\n",
            $result[0],
        );
        $this->assertSame(2, $result[1], 'the new line lands directly after the existing run');
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
        $this->assertSame("{{-- @var string \$title --}}\r\n" . $source, $result[0]);
    }

    #[Test]
    public function inserts_after_a_utf8_bom_rather_than_before_it(): void
    {
        $source = "\u{FEFF}<h1>{{ \$title }}</h1>\n";

        $result = TemplateAnnotator::annotate($source, ['title' => 'string']);

        $this->assertNotNull($result);
        $this->assertSame("\u{FEFF}{{-- @var string \$title --}}\n<h1>{{ \$title }}</h1>\n", $result[0]);
    }

    #[Test]
    public function emits_several_names_in_a_stable_alphabetical_order(): void
    {
        $result = TemplateAnnotator::annotate("<p>x</p>\n", ['title' => 'string', 'count' => 'int']);

        $this->assertNotNull($result);
        $this->assertSame(
            "{{-- @var int \$count --}}\n{{-- @var string \$title --}}\n<p>x</p>\n",
            $result[0],
        );
    }

    #[Test]
    public function adds_the_missing_separator_when_the_template_ends_on_its_contract_block(): void
    {
        $source = '{{-- @var string $title --}}';

        $result = TemplateAnnotator::annotate($source, ['body' => 'string']);

        $this->assertNotNull($result);
        $this->assertSame("{{-- @var string \$title --}}\n{{-- @var string \$body --}}\n", $result[0]);
        $this->assertSame(2, $result[1], 'the separator pushes the insertion onto the next line');
        $this->assertSame(['{{-- @var string $body --}}'], $result[2], 'only the new comment is reported as added');
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
        $source = "{{-- @var string \$caf\u{00e9} --}}\n<p>x</p>\n";

        $this->assertNull(TemplateAnnotator::annotate($source, ["caf\u{00e9}" => 'string']));
    }

    #[Test]
    public function is_idempotent_for_a_non_ascii_variable_name_it_wrote(): void
    {
        $vars = ["caf\u{00e9}" => 'string'];

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
        $this->assertSame(['{{-- @var string $body --}}'], $result[2]);
    }

    #[Test]
    public function recognises_a_raw_php_declaration_with_a_non_ascii_variable_name(): void
    {
        // A reader binding only the ASCII prefix sees `$caf` declared and `$café` undeclared, and
        // appends a second declaration for the same variable on every run.
        $source = "<?php /** @var string \$caf\u{00e9} */ ?>\n<p>x</p>\n";

        $this->assertNull(TemplateAnnotator::annotate($source, ["caf\u{00e9}" => 'string']));
    }

    #[Test]
    public function does_not_read_a_var_that_only_appears_inside_a_php_string(): void
    {
        $source = "<?php echo '@var string \$user'; ?>\n<p>x</p>\n";

        $result = TemplateAnnotator::annotate($source, ['user' => 'string']);

        $this->assertNotNull($result, 'a string literal is not a declaration');
        $this->assertSame(['{{-- @var string $user --}}'], $result[2]);
    }
}
