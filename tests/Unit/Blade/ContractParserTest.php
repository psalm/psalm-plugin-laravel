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
use Psalm\LaravelPlugin\Blade\ViewDataContract;

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

    private function dataContract(string $source): ViewDataContract
    {
        return $this->parser->parseDataContract($source, $this->compiler->compileString($source));
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
    public function a_var_comment_after_a_props_entry_wins_the_name(): void
    {
        // Document order, not kind: whichever declaration comes LATER in the template wins a
        // name collision, matching the `@props`/`@var` combo the "type a component prop" pattern
        // writes.
        $contract = $this->parse("@props(['user'])\n{{-- @var \\App\\Models\\User \$user --}}\n{{ \$user->name }}\n");

        $this->assertSame('\App\Models\User', $contract->vars['user']->typeString);
        $this->assertSame(2, $contract->vars['user']->declarationLine);
        $this->assertFalse($contract->vars['user']->optional);
    }

    #[Test]
    public function a_props_entry_after_a_var_comment_wins_the_name(): void
    {
        $contract = $this->parse("{{-- @var \\App\\Models\\User \$user --}}\n@props(['user'])\n{{ \$user->name }}\n");

        $this->assertSame('mixed', $contract->vars['user']->typeString);
        $this->assertSame(2, $contract->vars['user']->declarationLine);
        $this->assertFalse($contract->vars['user']->optional);
    }

    #[Test]
    public function a_props_call_inside_a_blade_comment_is_not_live(): void
    {
        $contract = $this->parse("{{-- @props(['a']) --}}\n<div></div>\n");

        $this->assertArrayNotHasKey('a', $contract->vars);
        $this->assertFalse($contract->propsUnknown);
    }

    #[Test]
    public function a_props_call_inside_verbatim_is_not_live(): void
    {
        $contract = $this->parse("@verbatim @props(['a']) @endverbatim\n<div></div>\n");

        $this->assertArrayNotHasKey('a', $contract->vars);
        $this->assertFalse($contract->propsUnknown);
    }

    #[Test]
    public function an_escaped_props_directive_is_not_live(): void
    {
        $contract = $this->parse("@@props(['a'])\n<div></div>\n");

        $this->assertArrayNotHasKey('a', $contract->vars);
        $this->assertFalse($contract->propsUnknown);
    }

    #[Test]
    public function a_closing_paren_inside_a_props_string_does_not_close_the_argument_list_early(): void
    {
        $contract = $this->parse("@props(['a' => ')'])\n<div>{{ \$a }}</div>\n");

        $this->assertArrayHasKey('a', $contract->vars);
        $this->assertTrue($contract->vars['a']->optional);
        $this->assertFalse($contract->propsUnknown);
    }

    #[Test]
    public function props_text_inside_another_directives_string_argument_is_not_a_declaration(): void
    {
        // The `@props(...)` text here sits inside `@php`'s own argument (a quoted string), never as
        // a live directive of its own; a scan that re-enters an already-consumed argument span
        // would misread it as a second, phantom `@props` declaring `$phantom`.
        $contract = $this->parse("@php(\$example = \"@props(['phantom'])\")\n<div></div>\n");

        $this->assertArrayNotHasKey('phantom', $contract->vars);
        $this->assertFalse($contract->propsUnknown);
    }

    #[Test]
    public function the_literal_text_props_inside_a_props_strings_default_value_does_not_confuse_the_scan(): void
    {
        $contract = $this->parse("@props(['hint' => 'Use @props for inputs'])\n<div>{{ \$hint }}</div>\n");

        $this->assertArrayHasKey('hint', $contract->vars);
        $this->assertTrue($contract->vars['hint']->optional);
        $this->assertFalse($contract->propsUnknown);
    }

    #[Test]
    public function a_php_comment_inside_a_props_argument_does_not_declare_a_nested_props_name(): void
    {
        $contract = $this->parse("@props(['a' /* @props(['b']) */])\n<div>{{ \$a }}</div>\n");

        $this->assertArrayHasKey('a', $contract->vars);
        $this->assertFalse($contract->vars['a']->optional);
        $this->assertArrayNotHasKey('b', $contract->vars);
        $this->assertFalse($contract->propsUnknown);
    }

    #[Test]
    public function a_blank_line_before_unrelated_parens_does_not_extend_the_previous_directive(): void
    {
        // Blade only skips SAME-LINE whitespace (`[ \t]*`) between a directive name and its `(`:
        // a scan that also skips newlines would let `@endif` (which never takes an argument)
        // swallow this unrelated, later parenthesised `@props` as its own "argument".
        $contract = $this->parse("@if(true)\nhello\n@endif\n(\n@props(['real'])\n)");

        $this->assertArrayHasKey('real', $contract->vars);
        $this->assertFalse($contract->propsUnknown);
    }

    #[Test]
    public function a_props_call_after_email_like_text_is_still_found(): void
    {
        // Blade's own directive regex is anchored `\B@`: a `@` preceded by a word character
        // (`a@example`) is never a directive at all, so the LATER, genuine `@props(...)` must
        // still be found independently, not swallowed as "@example"'s argument.
        $contract = $this->parse("a@example(@props(['real']))");

        $this->assertArrayHasKey('real', $contract->vars);
        $this->assertFalse($contract->propsUnknown);
    }

    #[Test]
    public function a_directive_argument_cannot_span_a_raw_php_tag(): void
    {
        // Blade tokenizes the WHOLE template first and compiles each T_INLINE_HTML segment
        // independently: a `(` before a raw PHP open tag and the real `@props(...)` after it are
        // never in the same segment, so `@unknown`'s "argument" can never reach past the tag to
        // swallow the genuine declaration.
        $contract = $this->parse("@unknown(<?php echo 'hello'; ?>\n@props(['real']))");

        $this->assertArrayHasKey('real', $contract->vars);
        $this->assertFalse($contract->propsUnknown);
    }

    #[Test]
    public function a_later_live_props_is_still_declared_alongside_a_phantom_free_scan(): void
    {
        $contract = $this->parse("@php(\$x = \"@props(['phantom'])\")\n@props(['real'])\n<div>{{ \$real }}</div>\n");

        $this->assertArrayHasKey('real', $contract->vars);
        $this->assertArrayNotHasKey('phantom', $contract->vars);
        $this->assertFalse($contract->propsUnknown);
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
    public function an_assign_target_is_excluded_from_the_template_read_set(): void
    {
        // $computed is echoed further down, so it IS read; this read set subtracts every name the
        // template binds for itself, and an assignment target is one of them.
        $contract = $this->parse("@php\n\$computed = \$input;\n@endphp\n{{ \$computed }}\n");

        $this->assertContains('input', $contract->readVariables);
        $this->assertNotContains('computed', $contract->readVariables);
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
    public function a_var_comment_inside_verbatim_is_not_a_declaration(): void
    {
        // Blade emits a @verbatim body as literal text: a `{{-- @var --}}` spelling inside it
        // never reaches the compiler as a comment and must not be read as a declaration either.
        $source = "@verbatim\n{{-- @var \\App\\Models\\User \$user --}}\n@endverbatim\n{{ \$user }}\n";

        $contract = $this->parse($source);

        $this->assertArrayNotHasKey('user', $contract->vars);
    }

    #[Test]
    public function a_var_comment_inside_a_php_block_is_not_a_declaration(): void
    {
        // The `{{-- --}}` text here is a PHP comment inside live code, not a Blade comment: Blade
        // never strips it, so it must not be read as a `@var` declaration.
        $source = "@php\n// {{-- @var \\App\\Models\\User \$user --}}\n@endphp\n{{ \$user }}\n";

        $contract = $this->parse($source);

        $this->assertArrayNotHasKey('user', $contract->vars);
    }

    #[Test]
    public function a_bare_cr_line_before_a_var_comment_does_not_throw_off_its_declaration_line(): void
    {
        // A bare `\r` ends a line for Blade and PHP alike; counting `\n` alone would misplace the
        // declaration by a line.
        $source = "first line\r{{-- @var \\App\\Models\\User \$user --}}\r{{ \$user->name }}\r";

        $contract = $this->parse($source);

        $this->assertSame(2, $contract->vars['user']->declarationLine);
    }

    #[Test]
    public function the_data_contract_carries_a_known_read_set(): void
    {
        $contract = $this->dataContract("{{-- @var string \$name --}}\n{{ \$name }}{{ \$extra }}\n");

        $this->assertFalse($contract->readsUnknown);
        $this->assertSame(['extra', 'name'], $contract->readVariables);
    }

    /**
     * The opposite of {@see self::foreach_subject_is_read_but_its_alias_is_local()}: the read set the
     * UnusedViewData rule consumes adds loop aliases back, because dropping them template-wide would
     * report a key the template provably uses as the loop's own subject.
     */
    #[Test]
    public function a_loop_alias_counts_as_a_read_for_the_data_contract(): void
    {
        $contract = $this->dataContract("@foreach(\$items as \$item)\n{{ \$item }}\n@endforeach\n");

        $this->assertFalse($contract->readsUnknown);
        $this->assertContains('item', $contract->readVariables);
    }

    #[Test]
    public function compact_literal_args_count_as_reads(): void
    {
        $contract = $this->dataContract("@php\n\$out = compact('first', 'second');\n@endphp\n{{ \$out }}\n");

        $this->assertFalse($contract->readsUnknown);
        $this->assertContains('first', $contract->readVariables);
        $this->assertContains('second', $contract->readVariables);
    }

    #[Test]
    public function a_non_literal_compact_arg_makes_the_read_set_unknown(): void
    {
        $contract = $this->dataContract("@php\n\$out = compact(\$names);\n@endphp\n{{ \$out }}\n");

        $this->assertTrue($contract->readsUnknown);
    }

    #[Test]
    public function extract_makes_the_read_set_unknown(): void
    {
        $contract = $this->dataContract("@php\nextract(\$bag);\n@endphp\ndone\n");

        $this->assertTrue($contract->readsUnknown);
    }

    /** `@props` compiles to `$$__key = ...` writes, so every component template lands here. */
    #[Test]
    public function a_variable_variable_makes_the_read_set_unknown(): void
    {
        $contract = $this->dataContract("@props(['heading'])\n{{ \$heading }}\n");

        $this->assertTrue($contract->readsUnknown);
    }

    /**
     * A parse failure returning an empty read set would read as "the template reads nothing", turning
     * every key the call site passes into a false positive.
     */
    #[Test]
    public function a_parse_failure_makes_the_read_set_unknown(): void
    {
        $contract = $this->parser->parseDataContract("{{ \$name }}\n", '<?php $oops = ; ?>');

        $this->assertTrue($contract->readsUnknown);
        $this->assertSame([], $contract->readVariables);
    }

    /** `@include` compiles `get_defined_vars()` into every call, so it can never mean "unknown". */
    #[Test]
    public function get_defined_vars_does_not_make_the_read_set_unknown(): void
    {
        $contract = $this->dataContract("@include('partial')\n{{ \$name }}\n");

        $this->assertFalse($contract->readsUnknown);
        $this->assertSame(['name'], $contract->readVariables);
    }

    #[Test]
    public function collects_a_destructured_loop_binding_as_a_local_variable(): void
    {
        // `@foreach ($rows as [$id, $name])`: both names are bound by the template, so neither is
        // something a call site passes.
        $contract = (new ContractParser())->parseDataContract(
            '',
            '<?php foreach ($rows as [$id, $name]): echo e($id) . e($name); endforeach;',
        );

        $this->assertSame(['id', 'name'], $contract->localVariables);
    }

    #[Test]
    public function collects_a_keyed_destructured_loop_binding(): void
    {
        $contract = (new ContractParser())->parseDataContract(
            '',
            '<?php foreach ($rows as $key => [\'a\' => $first]): echo e($first) . e($key); endforeach;',
        );

        $this->assertSame(['first', 'key'], $contract->localVariables);
    }

    /**
     * The gap this closes: a name the template assigns and then echoes is read (so it stays in the
     * read set) AND bound by the template, so the annotate pass must not declare it as an input.
     */
    #[Test]
    public function an_assigned_then_read_name_is_local(): void
    {
        $contract = (new ContractParser())->parseDataContract(
            '',
            "<?php (\$heading = 'Hello'); echo e(\$heading);",
        );

        $this->assertContains('heading', $contract->readVariables);
        $this->assertSame(['heading'], $contract->localVariables);
    }

    #[Test]
    public function a_closure_parameter_is_local_and_a_use_clause_is_not(): void
    {
        $contract = (new ContractParser())->parseDataContract(
            '',
            '<?php echo e(array_map(function ($item) use ($sep) { return $item . $sep; }, $items));',
        );

        $this->assertSame(['item'], $contract->localVariables);
        $this->assertContains('sep', $contract->readVariables);
        $this->assertContains('items', $contract->readVariables);
    }

    #[Test]
    public function an_arrow_function_parameter_and_a_catch_variable_are_local(): void
    {
        $contract = (new ContractParser())->parseDataContract(
            '',
            '<?php try { echo e(array_map(fn ($row) => $row, $rows)); } catch (\Throwable $error) { echo e($error); }',
        );

        $this->assertSame(['error', 'row'], $contract->localVariables);
        $this->assertContains('rows', $contract->readVariables);
    }

    #[Test]
    public function reads_a_declaration_with_a_non_ascii_variable_name(): void
    {
        $contract = (new ContractParser())->parseDeclarations("{{-- @var string \$men\u{00fc} --}}\n");

        $this->assertArrayHasKey("men\u{00fc}", $contract->vars);
    }
}
