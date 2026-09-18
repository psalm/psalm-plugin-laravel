<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\ContractParser;
use Psalm\LaravelPlugin\Blade\TemplateContract;

#[CoversClass(ContractParser::class)]
final class ContractParserTest extends TestCase
{
    private ContractParser $parser;

    private BladeCompiler $compiler;

    protected function setUp(): void
    {
        $this->parser = new ContractParser();
        $this->compiler = new BladeCompiler(new Filesystem(), \sys_get_temp_dir());
    }

    private function parse(string $source): TemplateContract
    {
        return $this->parser->parse($source, $this->compiler->compileString($source));
    }

    #[Test]
    public function extracts_a_var_comment_declaration(): void
    {
        $contract = $this->parse("{{-- @var \\App\\Models\\User \$user --}}\n{{ \$user->name }}\n");

        $this->assertArrayHasKey('user', $contract->vars);
        $this->assertSame('\App\Models\User', $contract->vars['user']->typeString);
        $this->assertSame(1, $contract->vars['user']->declarationLine);
        $this->assertFalse($contract->vars['user']->optional);
        $this->assertSame(['\App\Models\User'], \array_values($contract->contractVars()));
    }

    #[Test]
    public function extracts_a_var_whose_type_contains_spaces(): void
    {
        $contract = $this->parse(
            "{{-- @var \\Illuminate\\Support\\Collection<int, \\App\\Models\\User> \$users --}}\n{{ \$users->count() }}\n",
        );

        $this->assertSame(
            '\Illuminate\Support\Collection<int, \App\Models\User>',
            $contract->vars['users']->typeString ?? null,
        );
    }

    #[Test]
    public function extra_spaces_before_the_variable_name_do_not_leak_into_the_type(): void
    {
        $contract = $this->parse("{{-- @var \\App\\Models\\User  \$user --}}\n{{ \$user->name }}\n");

        $this->assertSame('\App\Models\User', $contract->vars['user']->typeString ?? null);
    }

    #[Test]
    public function a_template_with_no_contract_comments_declares_nothing_but_still_reads_variables(): void
    {
        $contract = $this->parse("Hello {{ \$name }}\n");

        $this->assertSame([], $contract->vars);
        $this->assertSame(['name'], $contract->readVariables);
    }

    #[Test]
    public function props_with_defaults_records_optional_and_bare_entries_as_required(): void
    {
        $contract = $this->parse("@props(['title' => 'Default', 'count'])\n<div>{{ \$title }}</div>\n");

        $this->assertFalse($contract->propsUnknown);
        $this->assertTrue($contract->vars['title']->optional);
        $this->assertSame('mixed', $contract->vars['title']->typeString);
        $this->assertFalse($contract->vars['count']->optional);
    }

    #[Test]
    public function props_built_from_a_variable_flags_props_unknown(): void
    {
        $contract = $this->parse("@props(\$defaults)\n<div></div>\n");

        $this->assertTrue($contract->propsUnknown);
    }

    #[Test]
    public function props_built_from_a_function_call_flags_props_unknown(): void
    {
        $contract = $this->parse("@props(array_merge(['a' => 1]))\n<div></div>\n");

        $this->assertTrue($contract->propsUnknown);
    }

    #[Test]
    public function suppression_attaches_to_the_next_real_statement_line(): void
    {
        $contract = $this->parse("{{-- @psalm-suppress UndefinedVariable --}}\n{{ \$foo }}\n");

        $this->assertSame(['UndefinedVariable'], $contract->suppressions[2] ?? null);
    }

    #[Test]
    public function suppression_is_dropped_when_nothing_follows(): void
    {
        $contract = $this->parse("content\n{{-- @psalm-suppress Foo --}}\n");

        $this->assertSame([], $contract->suppressions);
    }

    #[Test]
    public function foreach_subject_is_read_but_its_alias_is_local(): void
    {
        $contract = $this->parse("@foreach(\$items as \$item)\n{{ \$item }}\n@endforeach\n");

        $this->assertContains('items', $contract->readVariables);
        $this->assertNotContains('item', $contract->readVariables);
    }

    #[Test]
    public function nested_foreach_excludes_both_loop_aliases(): void
    {
        $contract = $this->parse(
            "@foreach(\$groups as \$group)\n@foreach(\$group as \$item)\n{{ \$item }}\n@endforeach\n@endforeach\n",
        );

        $this->assertContains('groups', $contract->readVariables);
        $this->assertNotContains('group', $contract->readVariables);
        $this->assertNotContains('item', $contract->readVariables);
    }

    #[Test]
    public function outer_read_shadowed_by_a_later_loop_alias_is_dropped_known_limitation(): void
    {
        // Pins the documented gap: loop aliases are excluded template-wide,
        // so the genuine outer read of $user before the loop is lost too.
        $contract = $this->parse("{{ \$user }}\n@foreach(\$rows as \$user)\n{{ \$user }}\n@endforeach\n");

        $this->assertContains('rows', $contract->readVariables);
        $this->assertNotContains('user', $contract->readVariables);
    }

    #[Test]
    public function forelse_subject_is_read_and_alias_stays_local(): void
    {
        $contract = $this->parse("@forelse(\$items as \$item)\n{{ \$item }}\n@empty\nnone\n@endforelse\n");

        $this->assertContains('items', $contract->readVariables);
        $this->assertNotContains('item', $contract->readVariables);
    }

    #[Test]
    public function ambient_variables_are_excluded_from_reads(): void
    {
        $contract = $this->parse("{{ \$slot }}{{ \$loop }}{{ \$attributes }}\n");

        $this->assertSame([], $contract->readVariables);
    }

    #[Test]
    public function assign_target_in_a_php_block_is_not_itself_counted_as_a_read(): void
    {
        // $computed is read again via the echo, so it legitimately appears; a write with
        // no later read (below) is the case the Assign-LHS exclusion actually guards.
        $contract = $this->parse("@php\n\$computed = \$input;\n@endphp\n{{ \$computed }}\n");

        $this->assertContains('input', $contract->readVariables);
        $this->assertContains('computed', $contract->readVariables);
    }

    #[Test]
    public function a_write_only_local_never_read_again_is_excluded(): void
    {
        $contract = $this->parse("@php\n\$temp = \$input;\n@endphp\ndone\n");

        $this->assertContains('input', $contract->readVariables);
        $this->assertNotContains('temp', $contract->readVariables);
    }

    #[Test]
    public function a_write_only_reference_assignment_target_is_excluded(): void
    {
        $contract = $this->parse("@php\n\$temp =& \$input;\n@endphp\ndone\n");

        $this->assertContains('input', $contract->readVariables);
        $this->assertNotContains('temp', $contract->readVariables);
    }

    #[Test]
    public function multi_byte_lines_before_a_var_comment_do_not_throw_off_its_declaration_line(): void
    {
        $source = "Héllo wörld with émoji 😀 and more unicode chars here\n"
            . "{{-- @var \\App\\Models\\User \$user --}}\n"
            . "{{ \$user->name }}\n";

        $contract = $this->parse($source);

        $this->assertSame(2, $contract->vars['user']->declarationLine);
    }

    #[Test]
    public function multi_byte_lines_before_a_suppression_do_not_throw_off_its_target_line(): void
    {
        $source = "Héllo wörld with émoji 😀 and more unicode chars here\n"
            . "{{-- @psalm-suppress UndefinedVariable --}}\n"
            . "{{ \$foo }}\n";

        $contract = $this->parse($source);

        $this->assertSame(['UndefinedVariable'], $contract->suppressions[3] ?? null);
    }
}
