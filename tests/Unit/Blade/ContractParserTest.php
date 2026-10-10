<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use Illuminate\Filesystem\Filesystem;
use Illuminate\View\Compilers\BladeCompiler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
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
    public function a_var_comment_after_an_escaped_php_directive_is_still_read(): void
    {
        $contract = $this->parse("@@php\n{{-- @var int \$n --}}\n@endphp\n");

        $this->assertArrayHasKey('n', $contract->vars);
        $this->assertSame('int', $contract->vars['n']->typeString);
    }

    #[Test]
    public function a_var_comment_after_an_escaped_verbatim_directive_is_still_read(): void
    {
        $contract = $this->parse("@@verbatim\n{{-- @var int \$n --}}\n@endverbatim\n");

        $this->assertArrayHasKey('n', $contract->vars);
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
    public function a_short_open_tag_is_a_boundary_when_enabled(): void
    {
        if (!\filter_var(\ini_get('short_open_tag'), \FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('short_open_tag is off for this run (PHP_INI_PERDIR, no runtime toggle).');
        }

        // Same shape as a_directive_argument_cannot_span_a_raw_php_tag(), with the bare `<?`
        // spelling Blade's own tokenizer also opens PHP mode for when short tags are on.
        $contract = $this->parse("@unknown(<? echo 'hello'; ?>\n@props(['real']))");

        $this->assertArrayHasKey('real', $contract->vars);
        $this->assertFalse($contract->propsUnknown);
    }

    #[Test]
    public function a_short_open_tag_is_not_a_boundary_when_disabled(): void
    {
        if (\filter_var(\ini_get('short_open_tag'), \FILTER_VALIDATE_BOOL)) {
            $this->markTestSkipped('short_open_tag is on for this run (PHP_INI_PERDIR, no runtime toggle).');
        }

        // Same shape as a_short_open_tag_is_a_boundary_when_enabled(): with short tags off, a
        // bare `<?` is inline HTML text, not a PHP opener, so it creates no segment boundary —
        // `@unknown`'s argument still spans straight through to the final `)`, exactly as any
        // other plain text would, same as before this directive-boundary work existed.
        $contract = $this->parse("@unknown(<? echo 'hello'; ?>\n@props(['real']))");

        $this->assertArrayNotHasKey('real', $contract->vars);
        $this->assertFalse($contract->propsUnknown);
    }

    #[Test]
    #[DataProvider('adjacentConstructProvider')]
    public function props_is_found_correctly_around_an_adjacent_blade_construct(string $source): void
    {
        $contract = $this->parse($source);

        $this->assertSame(['real'], array_keys($contract->vars));
        $this->assertFalse($contract->propsUnknown);
    }

    /** @return iterable<string, array{string}> */
    public static function adjacentConstructProvider(): iterable
    {
        // `\B@` requires a NON-word character before the `@`: a directive-shaped token glued
        // directly onto preceding text ("s1@props-two") is invisible to the scan, the same way
        // it is to Blade's own tokenizer, so this must not set propsUnknown and must not stop the
        // later, genuine `@props` from being found.
        yield 'a props-prefixed directive name glued to preceding text is not a directive' => [
            "s1@props-two(['fake'])\n@props(['real'])\n",
        ];

        // An escaped directive consumes its own balanced argument (however it spells its name)
        // without opening a gap the later, genuine `@props` could fall into.
        yield 'an escaped directive with a balanced argument does not hide a later @props' => [
            "@@foreach(\$i as \$x)\n@props(['real'])\n",
        ];
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

    #[Test]
    public function a_raw_var_for_view_data_is_a_contract_wherever_it_sits(): void
    {
        $source = "<h1>Hi</h1>\n<?php /** @var string \$title */ ?>\n@php\n/** @var int|null \$count */\n@endphp\n{{ \$title }}{{ \$count }}\n";

        $contract = $this->dataContract($source);

        $this->assertSame('string', $contract->vars['title']->typeString ?? null);
        $this->assertSame(2, $contract->vars['title']->declarationLine ?? null);
        $this->assertFalse($contract->vars['title']->optional ?? null);
        $this->assertSame('int|null', $contract->vars['count']->typeString ?? null);
        $this->assertSame(4, $contract->vars['count']->declarationLine ?? null);
        $this->assertTrue($contract->vars['count']->optional ?? null);
    }

    #[Test]
    public function every_nullable_spelling_is_optional_and_a_nested_null_is_not(): void
    {
        $source = "<?php\n/**\n * @var ?int \$a\n * @var null|Foo \$b\n * @var array<int, null> \$c\n * @var int \$d\n * @var array<int|null> \$e\n * @var int|NULL \$f\n */\n?>\n";

        $vars = $this->dataContract($source)->vars;

        $this->assertTrue($vars['a']->optional ?? null);
        $this->assertTrue($vars['b']->optional ?? null);
        $this->assertFalse($vars['c']->optional ?? null);
        $this->assertFalse($vars['d']->optional ?? null);
        $this->assertFalse($vars['e']->optional ?? null, 'a null inside a generic is not a nullable declaration');
        $this->assertTrue($vars['f']->optional ?? null);
    }

    #[Test]
    public function a_raw_var_for_a_name_the_template_binds_itself_stays_consumed_only(): void
    {
        $source = "@foreach (\$members as \$member)\n<?php /** @var Member \$member */ ?>\n{{ \$member }}\n@endforeach\n"
            . "<?php /** @var string \$alias */ \$alias = 'x'; ?>\n{{ \$alias }}\n";

        $contract = $this->dataContract($source);

        $this->assertArrayNotHasKey('member', $contract->vars);
        $this->assertArrayNotHasKey('alias', $contract->vars);
        $this->assertSame(['member', 'alias'], $contract->rawDeclaredVariables);
    }

    #[Test]
    public function a_blade_comment_declaration_wins_over_a_raw_one_for_the_same_name(): void
    {
        $source = "<?php /** @var string \$title */ ?>\n{{-- @var int \$title --}}\n{{ \$title }}\n";

        $contract = $this->dataContract($source);

        $this->assertSame('int', $contract->vars['title']->typeString ?? null);
        $this->assertSame([], $contract->rawDeclaredVariables, 'declared once, not twice');
    }

    #[Test]
    public function a_raw_var_binds_the_first_name_after_its_type_and_ignores_the_description(): void
    {
        $source = "<?php\n/**\n * @var string \$title The title/label for the \$other statistic\n * @var int \$count\n */\n?>\n{{ \$title }}{{ \$count }}\n";

        $contract = $this->dataContract($source);

        $this->assertSame(['title', 'count'], \array_keys($contract->vars));
        $this->assertSame('string', $contract->vars['title']->typeString);
        $this->assertSame(['title', 'count'], ContractParser::rawDeclaredNames($source), 'the lenient reader agrees');
    }

    #[Test]
    public function a_raw_var_type_with_spaces_inside_brackets_is_read_whole(): void
    {
        $source = "<?php\n/**\n"
            . " * @var \\Illuminate\\Support\\Collection<int, \\App\\Models\\User> \$users The users\n"
            . " * @var array{id: int, name: string} \$row A \$row\n"
            . " * @var Closure(Foo \$f): Bar \$callback Called later\n"
            . " */\n?>\n";

        $vars = $this->dataContract($source)->vars;

        $this->assertSame('\Illuminate\Support\Collection<int, \App\Models\User>', $vars['users']->typeString ?? null);
        $this->assertSame('array{id: int, name: string}', $vars['row']->typeString ?? null);
        $this->assertSame('Closure(Foo $f): Bar', $vars['callback']->typeString ?? null);
        $this->assertSame(['users', 'row', 'callback'], \array_keys($vars));
    }

    #[Test]
    public function a_nullable_raw_var_with_a_description_is_optional(): void
    {
        $source = "<?php\n/**\n * @var ?int \$a The a\n * @var int|null \$b The b\n * @var null|Foo \$c\n * @var int \$d The d\n */\n?>\n";

        $vars = $this->dataContract($source)->vars;

        $this->assertTrue($vars['a']->optional ?? null);
        $this->assertTrue($vars['b']->optional ?? null);
        $this->assertTrue($vars['c']->optional ?? null);
        $this->assertFalse($vars['d']->optional ?? null);
    }

    #[Test]
    public function a_raw_var_without_a_name_after_its_type_declares_nothing(): void
    {
        $source = "<?php\n/**\n * @var int the count\n * @var \$name string\n * @var array<int \$x\n */\n?>\n";

        $this->assertSame([], \array_keys($this->dataContract($source)->vars));
        $this->assertSame(['name'], ContractParser::rawDeclaredNames($source), 'a name-first line is no contract, but the name is stated');
    }

    #[Test]
    public function a_blade_owned_name_is_never_a_contract_in_either_spelling(): void
    {
        $source = "{{-- @var \\Illuminate\\Support\\ViewErrorBag \$errors --}}\n<?php\n/**\n * @var \\Illuminate\\View\\ComponentSlot \$slot\n * @var object{index: int} \$loop\n * @var string \$title\n */\n?>\n{{ \$errors }}{{ \$slot }}{{ \$title }}\n";

        $contract = $this->dataContract($source);

        $this->assertSame(['title'], \array_keys($contract->vars));
        $this->assertContains('slot', $contract->rawDeclaredVariables, 'still counts as declared');
    }

    #[Test]
    public function only_a_docblock_is_a_contract_not_a_plain_comment(): void
    {
        $source = "@php\n/* @var int \$block */\n@endphp\n<?php // @var int \$line ?>\n<?php /** @var int \$doc */ ?>\n{{ \$block }}{{ \$line }}{{ \$doc }}\n";

        $contract = $this->dataContract($source);

        $this->assertSame(['doc'], \array_keys($contract->vars));
        $this->assertEqualsCanonicalizing(['block', 'line'], $contract->rawDeclaredVariables, 'consumed-only, as before');
    }

    #[Test]
    public function a_raw_var_the_template_guards_or_types_mixed_is_optional(): void
    {
        $source = "<?php\n/**\n * @var string \$page\n * @var string \$flag\n * @var string \$maybe\n * @var mixed \$any\n * @var string \$required\n */\n?>\n"
            . "@php(\$page ??= 'x')\n{{ \$flag ?? 'no' }}\n@isset(\$maybe){{ \$maybe }}@endisset\n{{ \$any }}{{ \$required }}\n";

        $vars = $this->dataContract($source)->vars;

        foreach (['page', 'flag', 'maybe', 'any'] as $name) {
            $this->assertTrue($vars[$name]->optional ?? null, $name);
        }

        $this->assertFalse($vars['required']->optional ?? null);
    }

    #[Test]
    public function a_raw_var_inside_a_blade_comment_or_verbatim_body_is_not_a_declaration(): void
    {
        $source = "{{-- <?php /** @var string \$a */ ?> --}}\n@verbatim\n<?php /** @var string \$b */ ?>\n@endverbatim\n";

        $contract = $this->dataContract($source);

        $this->assertSame([], \array_keys($contract->vars));
        $this->assertSame([], $contract->rawDeclaredVariables);
    }

    #[Test]
    public function raw_declarations_are_added_after_the_fact_and_marked_raw(): void
    {
        $source = "{{-- @var int \$n --}}\n@foreach (\$rows as \$row)\n<?php /** @var Row \$row */ ?>\n@endforeach\n<?php /** @var string \$title */ ?>\n";
        $declarations = $this->parser->parseDeclarations($source);

        $this->assertSame(['n'], \array_keys($declarations->vars), 'the source-only half sees Blade comments alone');

        $contract = $this->parser->withRawDeclarations($declarations, $source, $this->compiler->compileString($source));

        $this->assertSame(['n', 'title'], \array_keys($contract->vars));
        $this->assertFalse($contract->vars['n']->raw);
        $this->assertTrue($contract->vars['title']->raw);
    }

    #[Test]
    public function a_blade_comment_declaration_binds_the_first_name_after_its_type(): void
    {
        $contract = $this->parser->parseDeclarations(
            "{{-- @var string \$title The title shown above \$page --}}\n{{-- @var Closure(Foo \$f): Bar \$cb Called with \$x --}}\n",
        );

        $this->assertSame(['title', 'cb'], \array_keys($contract->vars));
        $this->assertSame('string', $contract->vars['title']->typeString);
        $this->assertSame('Closure(Foo $f): Bar', $contract->vars['cb']->typeString);
    }
}
