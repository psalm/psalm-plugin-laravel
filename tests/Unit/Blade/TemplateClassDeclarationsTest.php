<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\TemplateClassDeclarations;

#[CoversClass(TemplateClassDeclarations::class)]
final class TemplateClassDeclarationsTest extends TestCase
{
    /**
     * @return list<array{name: string, line: int, start: int, end: int}>
     */
    private function find(string $source): array
    {
        return TemplateClassDeclarations::find($source, \token_get_all($source));
    }

    #[Test]
    public function every_kind_of_named_class_like_is_found_with_its_resolved_name_and_name_line(): void
    {
        $source = "<?php\nnamespace App\\Views;\n\nfinal class A {}\ninterface B {}\ntrait C {}\nenum D { case X; }\n";

        $found = $this->find($source);

        $this->assertSame(
            [['App\Views\A', 4], ['App\Views\B', 5], ['App\Views\C', 6], ['App\Views\D', 7]],
            \array_map(static fn(array $declaration): array => [$declaration['name'], $declaration['line']], $found),
        );
        $this->assertSame('final class A {}', \substr($source, $found[0]['start'], $found[0]['end'] - $found[0]['start'] + 1));
    }

    #[Test]
    public function a_global_class_and_one_nested_in_a_condition_are_found(): void
    {
        $found = $this->find("<?php\nif (!class_exists('Late')) {\n    class Late {}\n}\n");

        $this->assertSame(['Late'], \array_column($found, 'name'));
    }

    #[Test]
    public function class_constants_and_anonymous_classes_declare_nothing(): void
    {
        $this->assertSame([], $this->find("<?php\n\$a = Foo::class;\n\$b = new class {};\n"));
    }

    #[Test]
    public function a_shadow_that_does_not_parse_declares_nothing(): void
    {
        $this->assertSame([], $this->find("<?php\nclass Broken {\n"));
    }

    #[Test]
    public function blanking_keeps_every_byte_offset_and_line_break(): void
    {
        $source = "<?php\r\nclass A\r\n{\r\n    public function f(): int { return 1; }\r\n}\r\n\$kept = 1;\n";
        [$class] = $this->find($source);

        $blanked = TemplateClassDeclarations::blank($source, [[$class['start'], $class['end']]]);

        $this->assertSame(\strlen($source), \strlen($blanked));
        $this->assertSame(\substr_count($source, "\n"), \substr_count($blanked, "\n"));
        $this->assertSame(\substr_count($source, "\r"), \substr_count($blanked, "\r"));
        $this->assertStringNotContainsString('class A', $blanked);
        $this->assertStringEndsWith("\$kept = 1;\n", $blanked);
        $this->assertSame([], $this->find($blanked));
    }

    /** Parsing succeeds but name resolution throws on the duplicate alias: that template declares nothing, and nothing escapes. */
    #[Test]
    public function a_shadow_whose_names_cannot_be_resolved_declares_nothing(): void
    {
        $this->assertSame([], $this->find("<?php\nuse A\\B as Same;\nuse C\\D as Same;\nclass X {}\nstrlen();\n"));
    }

    /** A docblock left behind would attach to the next statement and carry its `@psalm-suppress` onto it. */
    #[Test]
    public function blanking_takes_the_attached_comments_with_the_declaration(): void
    {
        $source = "<?php\n/** @psalm-suppress TooFewArguments */\nclass X {}\nstrlen();\n";
        [$class] = $this->find($source);

        $blanked = TemplateClassDeclarations::blank($source, [[$class['start'], $class['end']]]);

        $this->assertStringNotContainsString('psalm-suppress', $blanked);
        $this->assertStringContainsString('strlen();', $blanked);
        $this->assertSame(\strlen($source), \strlen($blanked));
    }

    #[Test]
    public function the_suppressions_that_cover_a_declaration_are_returned_with_it(): void
    {
        $found = $this->find("<?php\n/** @psalm-suppress DuplicateClass */\nclass A {}\nclass B {}\n");

        $this->assertSame([['DuplicateClass'], []], \array_column($found, 'suppressed'));
    }
}
