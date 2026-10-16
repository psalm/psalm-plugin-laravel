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

    /** @param array<string, true> $provided */
    private function undeclared(string $source, array $provided = ['app' => true]): array
    {
        return $this->parser->undeclaredReads($source, $this->compiler->compileString($source), $provided);
    }

    /** `$message` reaches every mail view and a shared name every view: a `@var` for either is an IDE hint, not a contract. */
    #[Test]
    public function a_declaration_for_a_name_every_view_receives_does_not_activate_undeclared_reads(): void
    {
        $this->assertSame([], $this->undeclared("<?php /** @var \\Illuminate\\Mail\\Message \$message */ ?>\n{{ \$subject }}\n"));
        $this->assertSame([], $this->undeclared("<?php /** @var string \$siteName */ ?>\n{{ \$subject }}\n", ['siteName' => true]));
    }

    #[Test]
    public function a_real_declaration_activates_undeclared_reads_next_to_one_that_does_not_count(): void
    {
        $source = "<?php /** @var \\Illuminate\\Mail\\Message \$message */ ?>\n<?php /** @var string \$title */ ?>\n{{ \$title }} {{ \$subject }}\n";

        $this->assertSame(['subject'], $this->undeclared($source));
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function scopedBindings(): iterable
    {
        yield 'an arrow parameter named like a declaration leaves it a contract' => [
            "<?php /** @var string \$title */ ?>\n{{ \$title }}\n@php \$format = static fn (string \$title): string => strtoupper(\$title); @endphp\n{{ \$titel }}\n",
            ['titel'],
        ];
        yield 'a declaration the template normalises in place stays a contract' => [
            "<?php /** @var string \$title */ ?>\n@php \$title = \$title ?? 'Untitled'; @endphp\n{{ \$titel }}\n",
            ['titel'],
        ];
        yield 'a name only an arrow parameter binds is undeclared outside the arrow' => [
            "<?php /** @var list<int> \$ids */ ?>\n{{ implode(',', array_map(fn (int \$n) => \$n, \$ids)) }} {{ \$n }}\n",
            ['n'],
        ];
        yield 'a by-reference out-argument binds the name its @var types' => [
            "<?php parse_str('a=1', \$parts); /** @var array<string, string> \$parts */ ?>\n{{ \$parts['a'] }} {{ \$title }}\n",
            [],
        ];
        yield 'a loop alias hint alone is no contract' => [
            "@foreach (\$items as \$item)\n<?php /** @var \\App\\Item \$item */ ?>\n{{ \$item }}\n@endforeach\n{{ \$other }}\n",
            [],
        ];
    }

    /** @param list<string> $expected */
    #[Test]
    #[DataProvider('scopedBindings')]
    public function only_a_binding_in_the_templates_own_scope_turns_a_declaration_into_a_local(string $source, array $expected): void
    {
        $this->assertSame($expected, $this->undeclared($source));
    }

    /** @return iterable<string, array{string, list<string>}> */
    public static function livewireMarkers(): iterable
    {
        yield 'a functional Volt component' => [
            "<?php use function Livewire\\Volt\\{state}; state(['count' => 0]); /** @var string \$title */ ?>\n{{ \$title }} {{ \$count }}\n",
            [],
        ];
        yield 'an address that spells a directive is text' => [
            "<?php /** @var string \$title */ ?>\n<a href=\"mailto:help@this.example\">{{ \$titel }}</a>\n",
            ['titel'],
        ];
        yield 'an escaped directive is text' => [
            "<?php /** @var string \$title */ ?>\n<code>@@entangle('x')</code> {{ \$titel }}\n",
            ['titel'],
        ];
    }

    /** @param list<string> $expected */
    #[Test]
    #[DataProvider('livewireMarkers')]
    public function only_live_livewire_markup_keeps_the_net(string $source, array $expected): void
    {
        $this->assertSame($expected, $this->undeclared($source));
    }

    #[Test]
    public function render_targets_are_the_literal_views_of_component_and_each_directives(): void
    {
        $source = <<<'BLADE'
            @component('partials/badge') @endcomponent
            @componentFirst(['custom.alert', 'alert']) @endcomponent
            @component(App\View\Components\Card::class) @endcomponent
            @@component('escaped.text')
            {{-- @component('commented.out') --}}
            @each('partials.row', $rows, 'row', 'partials.empty')
            BLADE;

        $this->assertSame(
            [['partials.badge', 'custom.alert', 'alert'], ['partials.row']],
            $this->parser->renderTargets($source),
        );
    }

    #[Test]
    public function extracts_a_var_comment_declaration(): void
    {
        $contract = $this->parse("<?php /** @var \\App\\Models\\User \$user */ ?>\n{{ \$user->name }}\n");

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
            "<?php /** @var \\Illuminate\\Support\\Collection<int, \\App\\Models\\User> \$users */ ?>\n{{ \$users->count() }}\n",
        );

        $this->assertSame(
            '\Illuminate\Support\Collection<int, \App\Models\User>',
            $contract->vars['users']->typeString ?? null,
        );
    }

    #[Test]
    public function extra_spaces_before_the_variable_name_do_not_leak_into_the_type(): void
    {
        $contract = $this->parse("<?php /** @var \\App\\Models\\User  \$user */ ?>\n{{ \$user->name }}\n");

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
        $this->assertSame('string', $contract->vars['title']->defaultType);
        $this->assertFalse($contract->vars['count']->optional);
        $this->assertNull($contract->vars['count']->defaultType);
    }

    #[Test]
    public function a_props_name_that_is_no_assignable_variable_infers_no_default_type(): void
    {
        $contract = $this->parse("@props(['max-width' => 'x', 'this' => 'y', 'ok' => 'z'])\n");

        $this->assertNull($contract->vars['max-width']->defaultType);
        $this->assertNull($contract->vars['this']->defaultType);
        $this->assertSame('string', $contract->vars['ok']->defaultType);
    }

    /** PHP casts a decimal-integer string key to int, which reached `ContractVar`'s string name (#1848). */
    #[Test]
    #[DataProvider('integerNameProvider')]
    public function an_integer_like_props_or_aware_name_declares_nothing_and_keeps_the_rest(string $source): void
    {
        $contract = $this->parse($source);

        $this->assertSame(['size'], \array_keys($contract->vars));
        $this->assertSame('string', $contract->vars['size']->defaultType);
        $this->assertFalse($contract->propsUnknown);
        $this->assertSame(['size'], \array_keys($this->parser->parseDeclarations($source)->vars));
    }

    /** @return iterable<string, array{string}> */
    public static function integerNameProvider(): iterable
    {
        yield 'keyed' => ["@props(['1' => '2', 'size' => 'lg'])\n"];
        yield 'negative keyed' => ["@props(['-1' => '3', 'size' => 'lg'])\n"];
        yield 'bare' => ["@props(['1', 'size' => 'lg'])\n"];
        yield 'aware' => ["@aware(['1', '2' => 'x'])\n@props(['size' => 'lg'])\n"];
    }

    /** `ComponentAttributeBag::extractPropNames()` names a numeric-keyed entry by its value, as a bare one. */
    #[Test]
    public function a_numeric_keyed_props_entry_declares_its_value_as_a_required_name(): void
    {
        $contract = $this->parse("@props(['1' => 'color', '01' => 'tone', 2 => 'size'])\n");

        $this->assertSame(['color', 'tone', 'size'], \array_keys($contract->vars));
        $this->assertFalse($contract->vars['color']->optional);
        $this->assertNull($contract->vars['color']->defaultType);
        $this->assertFalse($contract->propsUnknown);
    }

    #[Test]
    public function a_numeric_keyed_props_entry_with_a_non_literal_value_is_unreadable(): void
    {
        $this->assertTrue($this->parse("@props(['1' => \$name])\n")->propsUnknown);
    }

    #[Test]
    public function a_props_name_any_aware_shares_infers_no_default_type(): void
    {
        $contract = $this->parse("@props(['color' => 'gray', 'size' => 'lg'])\n@aware(['color' => 'red'])\n");

        $this->assertNull($contract->vars['color']->defaultType);
        $this->assertSame('string', $contract->vars['size']->defaultType);
        // `@aware` is no declaration of its own: the contract and its optional flag are unchanged.
        $this->assertTrue($contract->vars['color']->optional);
        $this->assertFalse($contract->propsUnknown);
    }

    #[Test]
    public function an_unreadable_aware_leaves_every_props_default_untyped(): void
    {
        $contract = $this->parse("@aware(\$inherited)\n@props(['size' => 'lg'])\n");

        $this->assertNull($contract->vars['size']->defaultType);
        $this->assertFalse($contract->propsUnknown);
    }

    #[Test]
    public function an_unreadable_props_list_leaves_every_later_default_untyped(): void
    {
        $contract = $this->parse("@php \$defaults = ['size' => 3]; @endphp\n@props(\$defaults)\n@props(['size' => 'lg'])\n");

        $this->assertTrue($contract->propsUnknown);
        $this->assertNull($contract->vars['size']->defaultType);
    }

    #[Test]
    public function a_blade_comment_inside_an_aware_list_makes_it_unreadable(): void
    {
        // Blade strips the comment before compiling, so this `@aware` consumes `color`.
        $contract = $this->parse("@props(['color' => 'gray'])\n@aware(['co{{-- split --}}lor'])\n");

        $this->assertNull($contract->vars['color']->defaultType);
    }

    #[Test]
    public function a_blade_comment_inside_a_props_list_makes_it_unreadable(): void
    {
        $contract = $this->parse("@props(['si{{-- x --}}ze' => 'lg'])\n");

        $this->assertTrue($contract->propsUnknown);
        $this->assertSame([], $contract->vars);
    }

    #[Test]
    public function a_props_entry_for_a_blade_owned_name_infers_no_default_type(): void
    {
        $contract = $this->parse("@props(['slot' => '', 'errors' => [], 'size' => 'lg'])\n");

        $this->assertNull($contract->vars['slot']->defaultType);
        $this->assertNull($contract->vars['errors']->defaultType);
        $this->assertSame('string', $contract->vars['size']->defaultType);
    }

    #[Test]
    public function a_later_props_entry_for_an_already_declared_name_infers_no_default_type(): void
    {
        $contract = $this->parse("@props(['size'])\n@props(['size' => 'lg'])\n");

        $this->assertTrue($contract->vars['size']->optional);
        $this->assertNull($contract->vars['size']->defaultType);
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
    public function a_props_entry_wins_the_name_over_a_raw_docblock_in_either_order(): void
    {
        foreach (
            [
                "<?php /** @var \\App\\Models\\User \$user */ ?>\n@props(['user'])\n{{ \$user->name }}\n",
                "@props(['user'])\n<?php /** @var \\App\\Models\\User \$user */ ?>\n{{ \$user->name }}\n",
            ] as $source
        ) {
            $contract = $this->parse($source);

            $this->assertSame('mixed', $contract->vars['user']->typeString);
            $this->assertFalse($contract->vars['user']->optional);
        }
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
        $contract = $this->parse("@@php\n<?php /** @var int \$n */ ?>\n@endphp\n");

        $this->assertArrayHasKey('n', $contract->vars);
        $this->assertSame('int', $contract->vars['n']->typeString);
    }

    #[Test]
    public function a_var_comment_after_an_escaped_verbatim_directive_is_still_read(): void
    {
        $contract = $this->parse("@@verbatim\n<?php /** @var int \$n */ ?>\n@endverbatim\n");

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
            . "<?php /** @var \\App\\Models\\User \$user */ ?>\n"
            . "{{ \$user->name }}\n";

        $contract = $this->parse($source);

        $this->assertSame(2, $contract->vars['user']->declarationLine);
    }

    #[Test]
    public function a_var_comment_inside_verbatim_is_not_a_declaration(): void
    {
        // Blade emits a @verbatim body as literal text, so a docblock inside it is not PHP at all.
        $source = "@verbatim\n<?php /** @var \\App\\Models\\User \$user */ ?>\n@endverbatim\n{{ \$user }}\n";

        $contract = $this->parse($source);

        $this->assertArrayNotHasKey('user', $contract->vars);
    }

    #[Test]
    public function a_var_comment_inside_a_php_block_is_not_a_declaration(): void
    {
        // A `//` comment is not a docblock, whatever text it holds.
        $source = "@php\n// <?php /** @var \\App\\Models\\User \$user */ ?>\n@endphp\n{{ \$user }}\n";

        $contract = $this->parse($source);

        $this->assertArrayNotHasKey('user', $contract->vars);
    }

    #[Test]
    public function a_bare_cr_line_before_a_var_comment_does_not_throw_off_its_declaration_line(): void
    {
        // A bare `\r` ends a line for Blade and PHP alike; counting `\n` alone would misplace the
        // declaration by a line.
        $source = "first line\r<?php /** @var \\App\\Models\\User \$user */ ?>\r{{ \$user->name }}\r";

        $contract = $this->parse($source);

        $this->assertSame(2, $contract->vars['user']->declarationLine);
    }

    #[Test]
    public function the_data_contract_carries_a_known_read_set(): void
    {
        $contract = $this->dataContract("<?php /** @var string \$name */ ?>\n{{ \$name }}{{ \$extra }}\n");

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
    public function an_all_digit_compact_arg_reads_nothing(): void
    {
        $contract = $this->dataContract("@php\n\$out = compact('1', '-1', 'first');\n@endphp\n{{ \$out }}\n");

        $this->assertFalse($contract->readsUnknown);
        $this->assertContains('first', $contract->readVariables);
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
        $contract = (new ContractParser())->parseDeclarations("<?php /** @var string \$men\u{00fc} */ ?>\n", '');

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
        $this->assertFalse($contract->vars['count']->optional ?? null, 'a nullable type is still required');
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
    public function a_raw_var_type_spanning_several_lines_is_read_as_psalm_reads_it(): void
    {
        $source = "<?php\n/**\n * @var array{\n *     retryRate: float,\n *     manualRate: float,\n * } \$data The rates\n"
            . " * @var array<string, string> \$countryNames\n */\n?>\n";

        $vars = $this->dataContract($source)->vars;

        $this->assertSame(['data', 'countryNames'], \array_keys($vars));
        $this->assertSame('array{ retryRate: float, manualRate: float}', $vars['data']->typeString);
        $this->assertSame(3, $vars['data']->declarationLine);
        $this->assertSame(['data', 'countryNames'], ContractParser::rawDeclaredNames($source));
    }

    #[Test]
    public function a_multi_line_raw_var_type_keeps_going_past_an_asterisk_only_line(): void
    {
        $source = "<?php\n/**\n * @var array{\n *   a: int,\n *\n *   b: int\n * } \$data\n */\n?>\n";

        $this->assertSame('array{ a: int, b: int }', $this->dataContract($source)->vars['data']->typeString ?? null);
        $this->assertSame(['data'], ContractParser::rawDeclaredNames($source));
    }

    #[Test]
    public function a_multi_line_raw_var_type_stops_at_an_empty_line(): void
    {
        // Psalm's DocblockParser ends a tag at a truly empty line, so the shape never closes.
        $source = "<?php\n/**\n * @var array{\n *   a: int,\n\n *   b: int\n * } \$data\n */\n?>\n";

        $this->assertSame([], \array_keys($this->dataContract($source)->vars));
        $this->assertSame([], ContractParser::rawDeclaredNames($source));
    }

    #[Test]
    public function a_name_first_raw_var_never_binds_a_name_from_the_next_line(): void
    {
        $source = "<?php\n/**\n * @var \$user\n * \$other is described\n */\n?>\n";

        $this->assertSame([], \array_keys($this->dataContract($source)->vars));
        $this->assertSame(['user'], ContractParser::rawDeclaredNames($source));
    }

    #[Test]
    public function an_ignore_var_tag_turns_every_raw_var_of_its_docblock_into_no_contract(): void
    {
        // Psalm's DocblockParser::resolveTags() drops every `@var` of a docblock carrying either tag.
        // The names still count as stated: the annotator must not append to a docblock Psalm ignores.
        $sources = [
            "<?php\n/**\n * @var array{\n *   id: int,\n * } \$data\n * @psalm-ignore-var\n */\n?>\n",
            "<?php\n/**\n * @ignore-var\n * @var array{id: int} \$data\n */\n?>\n",
            "<?php /** @var int \$data\n * @psalm-ignore-var */ ?>\n",
        ];

        foreach ($sources as $source) {
            $this->assertSame([], \array_keys($this->dataContract($source)->vars), $source);
            $this->assertSame(['data'], ContractParser::rawDeclaredNames($source), $source);
        }

        $other = "<?php /** @psalm-ignore-var */ ?>\n<?php\n/**\n * @var int \$data\n * @psalm-ignore-variable-method\n */\n?>\n";
        $this->assertSame(['data'], \array_keys($this->dataContract($other)->vars), 'only a tag of that exact name, in the same docblock');
    }

    #[Test]
    public function a_multi_line_raw_var_type_continues_over_crlf_but_not_over_a_bare_cr(): void
    {
        // Psalm splits a docblock on LF only and strips CR after.
        $crlf = "<?php\r\n/**\r\n * @var array{\r\n *   id: int\r\n * } \$data\r\n */\r\n?>\r\n";
        $this->assertSame('array{ id: int }', $this->dataContract($crlf)->vars['data']->typeString ?? null);

        $cr = "<?php\r/**\r * @var array{\r * id:int\r * } \$data\r */\r?>";
        $this->assertSame([], \array_keys($this->dataContract($cr)->vars));
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
        $source = "<?php /** @var \\Illuminate\\Support\\ViewErrorBag \$errors */ ?>\n<?php\n/**\n * @var \\Illuminate\\View\\ComponentSlot \$slot\n * @var object{index: int} \$loop\n * @var string \$title\n */\n?>\n{{ \$errors }}{{ \$slot }}{{ \$title }}\n";

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
    public function a_raw_var_inside_a_blade_comment_or_verbatim_body_is_not_a_declaration(): void
    {
        $source = "{{-- <?php /** @var string \$a */ ?> --}}\n@verbatim\n<?php /** @var string \$b */ ?>\n@endverbatim\n";

        $contract = $this->dataContract($source);

        $this->assertSame([], \array_keys($contract->vars));
        $this->assertSame([], $contract->rawDeclaredVariables);
    }

    #[Test]
    public function declarations_are_props_plus_the_raw_docblocks_that_are_view_data(): void
    {
        $source = "<?php /** @var int \$n */ ?>\n@foreach (\$rows as \$row)\n<?php /** @var Row \$row */ ?>\n@endforeach\n<?php /** @var string \$title */ ?>\n";

        $contract = $this->parser->parseDeclarations($source, $this->compiler->compileString($source));

        $this->assertSame(['n', 'title'], \array_keys($contract->vars), 'the loop alias is a local, not view data');
    }

    #[Test]
    public function an_upper_case_open_tag_still_declares_a_raw_contract(): void
    {
        $contract = $this->dataContract("<?PHP /** @var string \$title */ ?>\n{{ \$title }}\n");

        $this->assertSame(['title'], \array_keys($contract->vars));
    }

    #[Test]
    public function a_var_inside_running_text_is_not_a_declaration(): void
    {
        $source = "<?php\n/** @deprecated Replace @var Foo \$user with a value object */\n/**\n * Some text @var int \$other here\n */\n?>\n{{ \$user }}{{ \$other }}\n";

        $this->assertSame([], \array_keys($this->dataContract($source)->vars));
        $this->assertSame([], ContractParser::rawDeclaredNames($source));
    }

    #[Test]
    public function a_var_tag_at_the_start_of_a_line_or_right_after_the_opener_still_declares(): void
    {
        $source = "<?php /** @var int \$a */ ?>\n<?php\n/**\n *   @var int \$b\n */\n?>\n";

        $this->assertSame(['a', 'b'], \array_keys($this->dataContract($source)->vars));
    }


    #[Test]
    public function only_an_equals_null_tail_makes_a_declaration_optional(): void
    {
        $source = "<?php\n/**\n * @var string|null \$a = null\n * @var string|null \$b = null The b, shown above \$a\n * @var string|null \$c\n * @var mixed \$d\n * @var string \$e =null\n * @var int \$f = nullable\n */\n?>\n"
            . "<?php /** @var string|null \$g = null */ ?>\n<?php /** @var string|null \$h */ ?>\n<?php /** @var Closure(int \$x): string \$i   =  NULL trailing */ ?>\n";

        $vars = $this->dataContract($source)->vars;

        foreach (['a', 'b', 'g', 'i'] as $name) {
            $this->assertTrue($vars[$name]->optional ?? null, $name);
        }

        foreach (['c', 'd', 'h'] as $name) {
            $this->assertFalse($vars[$name]->optional ?? null, "{$name}: nullable or mixed is required");
        }

        $this->assertTrue($vars['e']->optional ?? null, '`=null` without a space still reads as the marker');
        $this->assertFalse($vars['f']->optional ?? null, '`= nullable` is not `= null`');
        $this->assertSame('string|null', $vars['a']->typeString);
    }

    #[Test]
    public function with_null_widens_only_what_is_not_already_nullable(): void
    {
        $this->assertSame('string|null', ContractParser::withNull('string'));
        $this->assertSame('string|null', ContractParser::withNull('string|null'));
        $this->assertSame('?int', ContractParser::withNull('?int'));
        $this->assertSame('mixed', ContractParser::withNull('mixed'));
        $this->assertSame('array<int|null>|null', ContractParser::withNull('array<int|null>'));
        $this->assertSame('(Closure(int): string)|null', ContractParser::withNull('Closure(int): string'));
        $this->assertSame('int | NULL', ContractParser::withNull('int | NULL'));
    }

    #[Test]
    public function an_equals_null_followed_by_a_dash_or_word_is_not_the_marker(): void
    {
        $vars = $this->dataContract("<?php\n/**\n * @var string \$a = null-ish\n * @var string \$b = null\n * @var string \$c = null.\n */\n?>\n<?php /** @var string \$d = null-ish */ ?>\n")->vars;

        $this->assertFalse($vars['a']->optional ?? null);
        $this->assertTrue($vars['b']->optional ?? null);
        $this->assertFalse($vars['c']->optional ?? null);
        $this->assertFalse($vars['d']->optional ?? null);
    }

    #[Test]
    public function an_array_write_binds_its_root_variable_as_a_local(): void
    {
        $source = "<?php\n/** @var array<string, string> \$row */\n/** @var list<int> \$list */\n/** @var array<mixed> \$deep */\n/** @var string \$title */\n?>\n"
            . "@php(\$row['name'] = 'Ada')\n@php(\$list[] = 1)\n@php(\$deep['a']['b'] = 1)\n{{ \$title }}\n";

        $this->assertSame(['title'], \array_keys($this->dataContract($source)->vars));
    }

    #[Test]
    public function only_a_statement_level_docblock_declares(): void
    {
        $source = "<?php\nclass Helper {\n    /** @var string \$prop */\n    public string \$prop = '';\n    /** @var string \$method */\n    public function m(): void {}\n}\n"
            . "/** @var string \$fnDoc */\nfunction f() { /** @var string \$inFn */ return 1; }\n"
            . "\$items = [/** @var string \$inner */ 1];\n\$cb = function () { /** @var string \$nested */ return 1; };\n"
            . "/** @var string \$title */\n\$x = 1;\n?>\n";

        $this->assertSame(['title'], \array_keys($this->dataContract($source)->vars));
        $this->assertSame(['title'], ContractParser::rawDeclaredNames($source));
    }

    #[Test]
    public function an_opener_inside_a_description_is_not_a_declaration(): void
    {
        $source = "<?php\n/**\n * Example: /** @var string \$phantom\n * @var string \$real\n */\n?>\n";

        $this->assertSame(['real'], \array_keys($this->dataContract($source)->vars));
    }

    #[Test]
    public function a_bare_cr_ends_a_declaration_line(): void
    {
        $source = "<?php\r/**\r * @var string \$a\r * @var int \$b = null\r */\r?>\r";

        $vars = $this->dataContract($source)->vars;

        $this->assertSame(['a', 'b'], \array_keys($vars));
        $this->assertSame(3, $vars['a']->declarationLine);
        $this->assertTrue($vars['b']->optional);
    }

    #[Test]
    public function null_is_read_off_the_parsed_type_not_its_text(): void
    {
        foreach (['null', 'string|null', '?int', '(string|null)', '(mixed)', "'a|b'|null", 'mixed'] as $type) {
            $this->assertTrue(ContractParser::allowsNull($type), $type);
            $this->assertSame($type, ContractParser::withNull($type), $type);
        }

        foreach (['string', 'array<int|null>', "'null'", 'Closure(int): string', 'array<int $x'] as $type) {
            $this->assertFalse(ContractParser::allowsNull($type), $type);
        }

        $this->assertSame('(Closure(int): string)|null', ContractParser::withNull('Closure(int): string'));
        $this->assertSame('array<int|null>|null', ContractParser::withNull('array<int|null>'));
    }

    #[Test]
    public function the_suppression_context_names_the_docblock_tags_and_the_block_opening_line(): void
    {
        $source = "<h1>x</h1>\n<?php\n/**\n * @psalm-suppress InvalidDocblock, Foo\n * @var string \$a = null\n */\n?>\n";

        $this->assertSame([['InvalidDocblock'], 2], ContractParser::suppressionContext($source, 5));
        $this->assertSame([[], 9], ContractParser::suppressionContext($source, 9));
    }
}
