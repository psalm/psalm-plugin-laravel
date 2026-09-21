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
}
