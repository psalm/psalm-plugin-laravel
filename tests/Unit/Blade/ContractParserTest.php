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

    private function dataContract(string $source): ViewDataContract
    {
        return $this->parser->parseDataContract($source, $this->compiler->compileString($source));
    }

    #[Test]
    public function extracts_a_var_comment_declaration(): void
    {
        $contract = $this->dataContract("<?php /** @var \\App\\Models\\User \$user */ ?>\n{{ \$user->name }}\n");

        $this->assertArrayHasKey('user', $contract->vars);
        $this->assertSame('\App\Models\User', $contract->vars['user']->typeString);
        $this->assertSame(1, $contract->vars['user']->declarationLine);
        $this->assertFalse($contract->vars['user']->optional);
    }

    #[Test]
    public function extracts_a_var_whose_type_contains_spaces(): void
    {
        $contract = $this->dataContract(
            "<?php /** @var \\Illuminate\\Support\\Collection<int, \\App\\Models\\User> \$users */ ?>\n{{ \$users->count() }}\n",
        );

        $this->assertSame(
            '\Illuminate\Support\Collection<int, \App\Models\User>',
            $contract->vars['users']->typeString ?? null,
        );
    }

    #[Test]
    public function props_with_defaults_records_optional_and_bare_entries_as_required(): void
    {
        $contract = $this->dataContract("@props(['title' => 'Default', 'count'])\n<div>{{ \$title }}</div>\n");

        $this->assertFalse($contract->propsUnknown);
        $this->assertTrue($contract->vars['title']->optional);
        $this->assertSame('mixed', $contract->vars['title']->typeString);
        $this->assertSame('string', $contract->vars['title']->defaultType);
        $this->assertFalse($contract->vars['count']->optional);
        $this->assertNull($contract->vars['count']->defaultType);
    }

    /** PHP casts a decimal-integer string key to int, which reached `ContractVar`'s string name (#1848). */
    #[Test]
    #[DataProvider('integerNameProvider')]
    public function an_integer_like_props_or_aware_name_declares_nothing_and_keeps_the_rest(string $source): void
    {
        $contract = $this->dataContract($source);

        $this->assertSame(['size'], \array_keys($contract->vars));
        $this->assertSame('string', $contract->vars['size']->defaultType);
        $this->assertFalse($contract->propsUnknown);
        $this->assertSame(['size'], \array_keys($this->parser->parseDeclarations($source)->vars));
    }

    /** @return iterable<string, array{string}> */
    public static function integerNameProvider(): iterable
    {
        yield 'keyed' => ["@props(['1' => '2', 'size' => 'lg'])\n"];
        yield 'aware' => ["@aware(['1', '2' => 'x'])\n@props(['size' => 'lg'])\n"];
    }

    /** `ComponentAttributeBag::extractPropNames()` names a numeric-keyed entry by its value, as a bare one. */
    #[Test]
    public function a_numeric_keyed_props_entry_declares_its_value_as_a_required_name(): void
    {
        $contract = $this->dataContract("@props(['1' => 'color', '01' => 'tone', 2 => 'size'])\n");

        $this->assertSame(['color', 'tone', 'size'], \array_keys($contract->vars));
        $this->assertFalse($contract->vars['color']->optional);
        $this->assertNull($contract->vars['color']->defaultType);
        $this->assertFalse($contract->propsUnknown);
    }

    #[Test]
    public function a_props_name_any_aware_shares_infers_no_default_type(): void
    {
        $contract = $this->dataContract("@props(['color' => 'gray', 'size' => 'lg'])\n@aware(['color' => 'red'])\n");

        $this->assertNull($contract->vars['color']->defaultType);
        $this->assertSame('string', $contract->vars['size']->defaultType);
        // `@aware` is no declaration of its own: the contract and its optional flag are unchanged.
        $this->assertTrue($contract->vars['color']->optional);
        $this->assertFalse($contract->propsUnknown);
    }

    #[Test]
    public function an_unreadable_props_list_leaves_every_later_default_untyped(): void
    {
        $contract = $this->dataContract("@php \$defaults = ['size' => 3]; @endphp\n@props(\$defaults)\n@props(['size' => 'lg'])\n");

        $this->assertTrue($contract->propsUnknown);
        $this->assertNull($contract->vars['size']->defaultType);
    }

    #[Test]
    public function a_blade_comment_inside_a_props_list_makes_it_unreadable(): void
    {
        $contract = $this->dataContract("@props(['si{{-- x --}}ze' => 'lg'])\n");

        $this->assertTrue($contract->propsUnknown);
        $this->assertSame([], $contract->vars);
    }

    #[Test]
    public function a_props_entry_for_a_blade_owned_name_infers_no_default_type(): void
    {
        $contract = $this->dataContract("@props(['slot' => '', 'errors' => [], 'size' => 'lg'])\n");

        $this->assertNull($contract->vars['slot']->defaultType);
        $this->assertNull($contract->vars['errors']->defaultType);
        $this->assertSame('string', $contract->vars['size']->defaultType);
    }

    #[Test]
    public function props_built_from_a_variable_flags_props_unknown(): void
    {
        $contract = $this->dataContract("@props(\$defaults)\n<div></div>\n");

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
            $contract = $this->dataContract($source);

            $this->assertSame('mixed', $contract->vars['user']->typeString);
            $this->assertFalse($contract->vars['user']->optional);
        }
    }

    #[Test]
    public function a_props_call_inside_a_blade_comment_is_not_live(): void
    {
        $contract = $this->dataContract("{{-- @props(['a']) --}}\n<div></div>\n");

        $this->assertArrayNotHasKey('a', $contract->vars);
        $this->assertFalse($contract->propsUnknown);
    }

    #[Test]
    public function a_props_call_inside_verbatim_is_not_live(): void
    {
        $contract = $this->dataContract("@verbatim @props(['a']) @endverbatim\n<div></div>\n");

        $this->assertArrayNotHasKey('a', $contract->vars);
        $this->assertFalse($contract->propsUnknown);
    }

    #[Test]
    public function a_var_comment_after_an_escaped_php_directive_is_still_read(): void
    {
        $contract = $this->dataContract("@@php\n<?php /** @var int \$n */ ?>\n@endphp\n");

        $this->assertArrayHasKey('n', $contract->vars);
        $this->assertSame('int', $contract->vars['n']->typeString);
    }

    #[Test]
    public function a_closing_paren_inside_a_props_string_does_not_close_the_argument_list_early(): void
    {
        $contract = $this->dataContract("@props(['a' => ')'])\n<div>{{ \$a }}</div>\n");

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
        $contract = $this->dataContract("@php(\$example = \"@props(['phantom'])\")\n<div></div>\n");

        $this->assertArrayNotHasKey('phantom', $contract->vars);
        $this->assertFalse($contract->propsUnknown);
    }

    #[Test]
    public function foreach_subject_is_read_but_its_alias_is_local(): void
    {
        $contract = $this->dataContract("@foreach(\$items as \$item)\n{{ \$item }}\n@endforeach\n");

        $this->assertFalse($contract->readsUnknown);
        $this->assertContains('items', $contract->readVariables);
        $this->assertContains('item', $contract->readVariables);
        $this->assertSame(['item'], $contract->localVariables);
    }

    #[Test]
    public function ambient_variables_are_excluded_from_reads(): void
    {
        $contract = $this->dataContract("{{ \$slot }}{{ \$loop }}{{ \$attributes }}\n");

        $this->assertSame([], $contract->readVariables);
    }

    #[Test]
    public function an_assign_target_is_read_and_local(): void
    {
        // $computed is echoed further down, so it IS read, and the template binds it for itself.
        $contract = $this->dataContract("@php\n\$computed = \$input;\n@endphp\n{{ \$computed }}\n");

        $this->assertContains('input', $contract->readVariables);
        $this->assertContains('computed', $contract->readVariables);
        $this->assertSame(['computed'], $contract->localVariables);
    }

    #[Test]
    public function multi_byte_lines_before_a_var_comment_do_not_throw_off_its_declaration_line(): void
    {
        $source = "Héllo wörld with émoji 😀 and more unicode chars here\n"
            . "<?php /** @var \\App\\Models\\User \$user */ ?>\n"
            . "{{ \$user->name }}\n";

        $contract = $this->dataContract($source);

        $this->assertSame(2, $contract->vars['user']->declarationLine);
    }

    #[Test]
    public function a_bare_cr_line_before_a_var_comment_does_not_throw_off_its_declaration_line(): void
    {
        // A bare `\r` ends a line for Blade and PHP alike; counting `\n` alone would misplace the
        // declaration by a line.
        $source = "first line\r<?php /** @var \\App\\Models\\User \$user */ ?>\r{{ \$user->name }}\r";

        $contract = $this->dataContract($source);

        $this->assertSame(2, $contract->vars['user']->declarationLine);
    }

    #[Test]
    public function the_data_contract_carries_a_known_read_set(): void
    {
        $contract = $this->dataContract("<?php /** @var string \$name */ ?>\n{{ \$name }}{{ \$extra }}\n");

        $this->assertFalse($contract->readsUnknown);
        $this->assertSame(['extra', 'name'], $contract->readVariables);
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

    /** `$__data` is the engine's own array of the view's data, so a literal key reads that name. */
    #[Test]
    public function a_literal_key_of_the_engine_data_array_is_a_read(): void
    {
        $contract = $this->dataContract("{{ \$__data['name'] }}\n");

        $this->assertFalse($contract->readsUnknown);
        $this->assertSame(['name'], $contract->readVariables);
    }

    /** Any other use of `$__data` can reach every name, which no static walk can enumerate. */
    #[Test]
    public function any_other_use_of_the_engine_data_array_makes_the_read_set_unknown(): void
    {
        $this->assertTrue($this->dataContract("{{ \$__data[\$key] }}\n")->readsUnknown);
        $this->assertTrue($this->dataContract("{{ count(\$__data) }}\n")->readsUnknown);
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
    public function a_var_inside_running_text_is_not_a_declaration(): void
    {
        $source = "<?php\n/** @deprecated Replace @var Foo \$user with a value object */\n/**\n * Some text @var int \$other here\n */\n?>\n{{ \$user }}{{ \$other }}\n";

        $this->assertSame([], \array_keys($this->dataContract($source)->vars));
        $this->assertSame([], ContractParser::rawDeclaredNames($source));
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

        $this->assertSame([['InvalidDocblock', 'Foo'], 2], ContractParser::suppressionContext($source, 5));
        $this->assertSame([[], 9], ContractParser::suppressionContext($source, 9));
    }

    #[Test]
    public function the_suppression_context_reads_every_tag_and_list_entry_like_psalm(): void
    {
        $source = "<?php\n/**\n * @psalm-suppress A,B because of x\n * @psalm-suppress Mixed-Foo , C_2\n * @var string \$a\n */\n?>\n";

        $this->assertSame([['A', 'B', 'Mixed-Foo', 'C_2'], 1], ContractParser::suppressionContext($source, 5));
    }
}
