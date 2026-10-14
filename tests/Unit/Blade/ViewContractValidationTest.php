<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use Illuminate\View\Compilers\BladeCompiler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\ContractRegistry;
use Psalm\LaravelPlugin\Handlers\Views\ViewCallChain;
use Psalm\LaravelPlugin\Handlers\Views\ViewContractHandler;

/**
 * End-to-end proof that a template's `@var` docblock / `@props` contract is enforced at the
 * `view()` call sites that feed it. A real `vendor/bin/psalm` run is the only way to pin it: the
 * contract only exists after the Blade bootstrap has compiled the fixture's templates, and the
 * check reads argument types Psalm infers during analysis.
 *
 * phpt type tests cannot cover this: they analyze against Testbench's view root inside `vendor/`,
 * and no `tests/Type/psalm-*.xml` config enables Blade.
 */
#[CoversClass(ViewContractHandler::class)]
#[CoversClass(ViewCallChain::class)]
#[CoversClass(ContractRegistry::class)]
#[Group('subprocess')]
final class ViewContractValidationTest extends TestCase
{
    use AnalysesFixtureApp;

    private const FIXTURE = __DIR__ . '/Fixtures/ViewContract';

    private const MISSING = 'MissingViewVariable';

    private const WRONG_TYPE = 'InvalidViewVariableType';

    /**
     * The command a case's `$config` resolves to. Cases naming the same config share one run.
     *
     * @return list<string>
     */
    private function arguments(string $config): array
    {
        return ['-c', $config, '--no-cache', '--threads=1', '--no-progress', '--output-format=json'];
    }

    /**
     * Contract issues only, keyed by the fixture file that caused them — the fixture reports plenty
     * of unrelated issues at errorLevel 1 and the shadows add more.
     *
     * @return list<array{type: string, file: string, message: string}>
     */
    private function contractIssues(string $config): array
    {
        $issues = [];

        foreach ($this->fixtureIssues(self::FIXTURE, $this->arguments($config)) as $issue) {
            $type = (string) $issue['type'];

            if ($type !== self::MISSING && $type !== self::WRONG_TYPE) {
                continue;
            }

            $issues[] = [
                'type' => $type,
                'file' => \basename((string) $issue['file_name']),
                'message' => (string) $issue['message'],
            ];
        }

        return $issues;
    }

    /**
     * @param list<array{type: string, file: string, message: string}> $issues
     *
     * @return list<array{type: string, file: string, message: string}>
     */
    private function forFile(array $issues, string $file): array
    {
        return \array_values(\array_filter($issues, static fn(array $issue): bool => $issue['file'] === $file));
    }

    #[Test]
    public function merge_data_is_respected_for_every_factory_form(): void
    {
        $issues = $this->contractIssues('psalm.xml');
        $this->assertSame([], $this->forFile($issues, 'MergeData.php'), \var_export($issues, true));
        $wrong = $this->forFile($issues, 'MergeDataWrong.php');
        $this->assertCount(3, $wrong, \var_export($issues, true));
        foreach ($wrong as $issue) {
            $this->assertSame(self::WRONG_TYPE, $issue['type']);
        }
    }

    #[Test]
    public function a_declared_variable_absent_from_the_data_array_is_reported(): void
    {
        $issues = $this->contractIssues('psalm.xml');
        $reported = $this->forFile($issues, 'MissingVariable.php');

        $this->assertCount(2, $reported, \var_export($issues, true));

        foreach ($reported as $issue) {
            $this->assertSame(self::MISSING, $issue['type']);
        }

        $messages = \implode("\n", \array_column($reported, 'message'));
        $this->assertStringContainsString("'name'", $messages);
        $this->assertStringContainsString("'age'", $messages);
        $this->assertStringContainsString("'profile'", $messages);
    }

    #[Test]
    public function a_data_value_that_does_not_satisfy_the_declared_type_is_reported(): void
    {
        $issues = $this->contractIssues('psalm.xml');
        $reported = $this->forFile($issues, 'WrongType.php');

        $this->assertCount(1, $reported, \var_export($issues, true));
        $this->assertSame(self::WRONG_TYPE, $reported[0]['type']);
        $this->assertStringContainsString('$name', $reported[0]['message']);
        $this->assertStringContainsString('string', $reported[0]['message']);
    }

    /**
     * `View::gatherData()` renders every `Renderable` data value to a string before the template sees
     * it, so one passed for a `string` declaration is correct. Only the `Renderable` atomics are
     * rewritten: an unrelated object and a `Renderable|int` union are still wrong, which also proves
     * the check runs on the shapes of the silent file.
     */
    #[Test]
    public function a_renderable_value_satisfies_a_string_declaration(): void
    {
        $issues = $this->contractIssues('psalm.xml');

        $this->assertSame([], $this->forFile($issues, 'RenderableValue.php'), \var_export($issues, true));

        $wrong = $this->forFile($issues, 'NonRenderableValue.php');
        $this->assertCount(2, $wrong, \var_export($issues, true));
        $this->assertSame([self::WRONG_TYPE, self::WRONG_TYPE], \array_column($wrong, 'type'));

        $messages = \implode("\n", \array_column($wrong, 'message'));
        $this->assertStringContainsString('DateTimeImmutable', $messages);
        $this->assertStringContainsString('Illuminate\Contracts\View\View|int', $messages);
    }

    #[Test]
    public function a_with_chain_contributes_its_key_to_the_same_contract_check(): void
    {
        $issues = $this->contractIssues('psalm.xml');
        $reported = $this->forFile($issues, 'WithChain.php');

        // The inner view('greeting') supplies no data at all; only the outermost call sees the
        // whole chain, so the single issue must be the type mismatch, never a missing 'name'.
        $this->assertCount(1, $reported, \var_export($issues, true));
        $this->assertSame(self::WRONG_TYPE, $reported[0]['type']);
    }

    /**
     * `with()` dispatches on `is_array($key)`, never on the argument count. Both halves are pinned
     * together: reading the count instead makes the first case silent AND the second a false
     * positive, and each alone could be explained by an unrelated decline.
     */
    #[Test]
    public function a_single_key_with_call_assigns_null_and_an_array_key_merges(): void
    {
        $issues = $this->contractIssues('psalm.xml');

        $nulled = $this->forFile($issues, 'WithNullValue.php');
        $this->assertCount(1, $nulled, \var_export($issues, true));
        $this->assertSame(self::WRONG_TYPE, $nulled[0]['type']);
        $this->assertStringContainsString('null', $nulled[0]['message']);

        $this->assertSame([], $this->forFile($issues, 'WithArrayAndSecondArgument.php'), \var_export($issues, true));
    }

    /**
     * A MailMessage chain reaches `SimpleMessage::with($line)`, which appends a notification line
     * and binds no template data — so the chain's key set stays closed and the declared variable is
     * still reported as missing.
     */
    #[Test]
    public function a_mail_message_with_call_contributes_no_view_data(): void
    {
        $issues = $this->contractIssues('psalm.xml');
        $reported = $this->forFile($issues, 'MailMessageChain.php');

        $this->assertCount(1, $reported, \var_export($issues, true));
        $this->assertSame(self::MISSING, $reported[0]['type']);
        $this->assertStringContainsString("'name'", $reported[0]['message']);
    }

    /**
     * Laravel merges `Component::data()` — the public properties and methods — into the view a
     * class component renders, so a `render()` that passes only `['component' => $this]` still
     * supplies every declared name. All three halves are asserted together: a blanket decline for
     * Component subclasses would pass the first and silently lose the second.
     */
    #[Test]
    public function a_class_components_public_properties_count_as_supplied(): void
    {
        $issues = $this->contractIssues('psalm.xml');

        $this->assertSame([], $this->forFile($issues, 'ComponentRender.php'), \var_export($issues, true));

        $wrongType = $this->forFile($issues, 'ComponentWrongType.php');
        $this->assertCount(1, $wrongType, \var_export($issues, true));
        $this->assertSame(self::WRONG_TYPE, $wrongType[0]['type']);
        $this->assertStringContainsString('$count', $wrongType[0]['message']);

        // A userland data() can add any key, so the supplied set is open rather than enumerated.
        $this->assertSame([], $this->forFile($issues, 'ComponentDataOverride.php'), \var_export($issues, true));
    }

    /**
     * `renderComponent()` ends in `$view->with($componentData)`, which merges last, so a public
     * property overrides the same key passed explicitly from `render()`.
     */
    #[Test]
    public function a_class_components_data_overrides_the_same_key_passed_from_render(): void
    {
        $issues = $this->contractIssues('psalm.xml');

        $this->assertSame([], $this->forFile($issues, 'ComponentOverlappingKey.php'), \var_export($issues, true));
    }

    #[Test]
    public function a_response_view_call_is_checked_like_the_helper(): void
    {
        $issues = $this->contractIssues('psalm.xml');
        $reported = $this->forFile($issues, 'ResponseView.php');

        $this->assertCount(1, $reported, \var_export($issues, true));
        $this->assertSame(self::WRONG_TYPE, $reported[0]['type']);
    }

    #[Test]
    public function a_mailable_view_chain_resolves_through_its_userland_subclass(): void
    {
        $issues = $this->contractIssues('psalm.xml');
        $reported = $this->forFile($issues, 'MailableChain.php');

        $this->assertCount(1, $reported, \var_export($issues, true));
        $this->assertSame(self::WRONG_TYPE, $reported[0]['type']);
    }

    /**
     * A `@props` entry with a literal default is optional and one without is not. Both halves are
     * asserted together: a template whose props failed to parse would be silent for BOTH, and the
     * optional case alone could not tell that apart from the gate working.
     */
    #[Test]
    public function only_the_props_entry_without_a_default_is_required(): void
    {
        $issues = $this->contractIssues('psalm.xml');

        $required = $this->forFile($issues, 'MissingRequiredProp.php');
        $this->assertCount(1, $required, \var_export($issues, true));
        $this->assertSame(self::MISSING, $required[0]['type']);
        $this->assertStringContainsString("'subtitle'", $required[0]['message']);

        $this->assertSame([], $this->forFile($issues, 'OptionalProp.php'));
    }

    /**
     * #1828: a literal default types the template body only. A caller may pass any value for the
     * prop, so the call site is never checked against the default's type; the declared-type case
     * beside it proves the check itself runs.
     */
    #[Test]
    public function a_props_default_type_is_never_checked_against_the_call_site(): void
    {
        $issues = $this->contractIssues('psalm.xml');

        $this->assertSame([], $this->forFile($issues, 'PropsDefaultMismatch.php'), \var_export($issues, true));
        $this->assertSame([self::WRONG_TYPE], \array_column($this->forFile($issues, 'WrongType.php'), 'type'));
    }

    /**
     * An open key set silences the missing-variable check without silencing the type check: the
     * keys that ARE known are still known.
     */
    #[Test]
    public function merge_data_leaves_the_key_set_open_but_keeps_the_type_check(): void
    {
        $issues = $this->contractIssues('psalm.xml');
        $reported = $this->forFile($issues, 'UnsealedData.php');

        $this->assertCount(1, $reported, \var_export($issues, true));
        $this->assertSame(self::WRONG_TYPE, $reported[0]['type']);
    }

    /**
     * A template that declares nothing still claims its view name. Two view roots both hold
     * `dup.blade.php`; the first root's file declares nothing and is the one Laravel renders, so the
     * second root's `@var $x` must never reach this call site.
     */
    #[Test]
    public function a_declaration_less_template_shadows_a_declaring_one_in_a_later_view_root(): void
    {
        $issues = $this->contractIssues('psalm.xml');

        $this->assertSame([], $this->forFile($issues, 'ShadowedTemplate.php'), \var_export($issues, true));
    }

    /**
     * A template the compile pass could not process still claims its view name. Laravel renders the
     * first root's file whether or not this plugin could compile it, so a same-named template in a
     * later root must not inherit the name through the failure branch.
     */
    #[Test]
    public function a_template_that_fails_to_compile_still_claims_its_view_name(): void
    {
        $issues = $this->contractIssues('psalm.xml');

        $this->assertSame([], $this->forFile($issues, 'UncompilableFirstRoot.php'), \var_export($issues, true));
    }

    /**
     * Every binder is resolved by name as well as by position, and so is `with()`. The lookup keys
     * are Laravel's own parameter names (`$view`, `$data`, `$key`, `$value`), so a rename upstream
     * silently stops resolving the argument and this is the only thing that would catch it.
     */
    #[Test]
    public function named_arguments_resolve_on_the_binder_and_on_the_chain(): void
    {
        $issues = $this->contractIssues('psalm.xml');
        $reported = $this->forFile($issues, 'NamedArguments.php');

        $this->assertCount(2, $reported, \var_export($issues, true));

        foreach ($reported as $issue) {
            $this->assertSame(self::WRONG_TYPE, $issue['type']);
        }
    }

    #[Test]
    public function the_static_facade_form_is_checked_like_the_helper(): void
    {
        $issues = $this->contractIssues('psalm.xml');
        $reported = $this->forFile($issues, 'StaticFacadeForm.php');

        $this->assertCount(1, $reported, \var_export($issues, true));
        $this->assertSame(self::WRONG_TYPE, $reported[0]['type']);
    }

    /** Each of these pins a different decline gate, with everything else about the call held equal. */
    #[Test]
    public function every_decline_gate_stays_silent(): void
    {
        $issues = $this->contractIssues('psalm.xml');

        // Complete and well-typed data.
        $this->assertSame([], $this->forFile($issues, 'Correct.php'));
        // Same shape as MissingVariable.php, but the name is not a literal.
        $this->assertSame([], $this->forFile($issues, 'DynamicName.php'));
        // Same shape as MissingVariable.php, but the template declares nothing.
        $this->assertSame([], $this->forFile($issues, 'NoContract.php'));
        // A spread shifts every position after it, so no argument can be read by position.
        $this->assertSame([], $this->forFile($issues, 'SpreadArgs.php'));
        // A raw `<?php` docblock types a local the template assigns itself, so it is never a
        // contract a call site has to satisfy.
        $this->assertSame([], $this->forFile($issues, 'RawLocalHint.php'));
    }

    /**
     * A raw `@var` is a contract wherever it sits (a `<?php` block or `@php`), unless the name is one
     * the template binds itself. The nullable one is optional, like a `@props` default.
     */
    #[Test]
    public function a_raw_var_for_view_data_is_a_contract_and_a_nullable_one_is_optional(): void
    {
        $issues = $this->contractIssues('psalm.xml');

        $missing = $this->forFile($issues, 'RawMissing.php');
        $this->assertCount(1, $missing, \var_export($issues, true));
        $this->assertSame(self::MISSING, $missing[0]['type']);
        $this->assertStringContainsString("'title'", $missing[0]['message']);

        $this->assertSame([], $this->forFile($issues, 'RawOptional.php'), 'the nullable `$count` may be omitted');

        $wrong = $this->forFile($issues, 'RawWrongType.php');
        $this->assertCount(1, $wrong, \var_export($issues, true));
        $this->assertSame(self::WRONG_TYPE, $wrong[0]['type']);
        $this->assertStringContainsString('$title', $wrong[0]['message']);
    }

    #[Test]
    public function a_raw_var_for_a_loop_alias_is_not_a_contract(): void
    {
        $this->assertSame([], $this->forFile($this->contractIssues('psalm.xml'), 'RawLoopAlias.php'));
    }

    #[Test]
    public function a_raw_var_for_a_blade_owned_name_or_in_a_plain_comment_is_no_contract(): void
    {
        $this->assertSame([], $this->forFile($this->contractIssues('psalm.xml'), 'RawNotContracts.php'));
    }

    /** The template's own `use` alias is invisible to the contract: the type check declines, the presence check stays. */
    #[Test]
    public function a_short_class_name_declines_the_type_check_but_keeps_the_presence_check(): void
    {
        $issues = $this->contractIssues('psalm.xml');

        $this->assertSame([], $this->forFile($issues, 'RawShortName.php'), \var_export($issues, true));

        $missing = $this->forFile($issues, 'RawShortNameMissing.php');
        $this->assertCount(1, $missing, \var_export($issues, true));
        $this->assertSame(self::MISSING, $missing[0]['type']);
    }

    #[Test]
    public function a_components_public_render_leaves_the_data_set_open(): void
    {
        $this->assertSame([], $this->forFile($this->contractIssues('psalm.xml'), 'ComponentRenderPublic.php'));
    }

    #[Test]
    public function a_raw_optional_marker_on_a_type_without_null_is_reported_at_the_declaration(): void
    {
        $reported = [];

        foreach ($this->fixtureIssues(self::FIXTURE, $this->arguments('psalm.xml')) as $issue) {
            if ($issue['type'] === 'InvalidDocblock' && \basename((string) $issue['file_name']) === 'raw-bad-optional.blade.php') {
                $reported[] = $issue;
            }
        }

        // `$bad` only: a bracketed or `mixed` type allows null, a native `@psalm-suppress` silences
        // its own declaration, and a Blade suppression above the block silences a multi-line block.
        $this->assertCount(1, $reported, \var_export($reported, true));
        $this->assertSame(3, $reported[0]['line_from']);
        $this->assertStringContainsString('write `string|null $bad = null`', (string) $reported[0]['message']);
    }

    /** A closure composer is opaque, so a call site is judged on neither presence nor type. */
    #[Test]
    public function a_composed_view_is_checked_for_neither_presence_nor_type(): void
    {
        $issues = $this->contractIssues('psalm.xml');

        $this->assertSame([], $this->forFile($issues, 'ComposedWrongType.php'), \var_export($issues, true));
        $this->assertSame([], $this->forFile($issues, 'ComposedMissing.php'), \var_export($issues, true));
    }

    /**
     * A class composer exempts only the keys it passes: the other declared variables are still
     * judged, and a composer-supplied key is not type-checked either, since the composer runs after
     * the call site and may overwrite it. Wildcard and `Class@method` composers are unioned, creators
     * and trait methods are read, and one unreadable composer leaves its view unchecked. The wildcard
     * observers bootstrap/app.php registers on the dispatcher count as no composer at all.
     */
    #[Test]
    public function a_composer_exempts_only_the_keys_it_supplies(): void
    {
        $reported = [];

        foreach ($this->forFile($this->contractIssues('psalm.xml'), 'ComposerCallSites.php') as $issue) {
            \preg_match("/View '([^']+)' declares \\$(\\w+)/", $issue['message'], $matches);
            $reported[] = "{$issue['type']} {$matches[1]} \${$matches[2]}";
        }

        \sort($reported);

        $this->assertSame(
            [
                self::WRONG_TYPE . ' composer-literal $count',
                self::MISSING . ' composer-accessor $count',
                self::MISSING . ' composer-created $title',
                self::MISSING . ' composer-inherited $count',
                self::MISSING . ' composer-literal $count',
                self::MISSING . ' composer-wild.page $other',
                self::MISSING . ' observed $title',
            ],
            $reported,
        );
    }

    /**
     * Only a statement-level docblock of the template's own scope declares: a property or method
     * docblock, one inside a closure or an expression, and a `$row[...] = ` local are no contract,
     * and a `use`-aliased qualified name declines the type check.
     */
    #[Test]
    public function only_statement_level_docblocks_declare_and_aliased_names_decline_the_type_check(): void
    {
        $issues = $this->contractIssues('psalm.xml');

        $this->assertSame([], $this->forFile($issues, 'RawScope.php'), \var_export($issues, true));

        foreach ($this->fixtureIssues(self::FIXTURE, $this->arguments('psalm.xml')) as $issue) {
            $this->assertFalse(
                $issue['type'] === 'InvalidDocblock' && \str_contains((string) $issue['message'], 'Optional view variable')
                    && \basename((string) $issue['file_name']) === 'raw-scope.blade.php',
                'a property docblock is not a declaration',
            );
        }
    }

    /**
     * `@include` and `@includeIf` also hand the partial the includer's whole scope, so only the keys
     * their array passes are checked, by type.
     */
    #[Test]
    public function an_include_value_of_the_wrong_type_is_reported_on_its_line_and_a_mixed_one_declines(): void
    {
        $reported = $this->templateContractIssues('wrong-type.blade.php');

        $this->assertSame([3, 5], \array_column($reported, 'line_from'), \var_export($reported, true));

        foreach ($reported as $issue) {
            $this->assertSame(self::WRONG_TYPE, $issue['type']);
            $this->assertStringContainsString('$title as string, but int', (string) $issue['message']);
        }
    }

    /** The partial inherits the includer's scope, so a key the array omits may still arrive. */
    #[Test]
    public function a_key_an_include_omits_is_not_reported(): void
    {
        $this->assertSame([], $this->templateContractIssues('omitted.blade.php'));
    }

    /** `@includeIsolated` hands on no scope: its array is the whole data set, as at a `view()` call. */
    #[Test]
    public function an_isolated_include_omitting_a_declared_variable_is_reported_on_its_line(): void
    {
        $this->requireIncludeIsolated();

        $reported = $this->templateContractIssues('isolated.blade.php');

        $this->assertCount(1, $reported, \var_export($reported, true));
        $this->assertSame(self::MISSING, $reported[0]['type']);
        $this->assertSame(2, $reported[0]['line_from']);
        $this->assertStringContainsString("View 'inc.card' declares \$count", (string) $reported[0]['message']);
    }

    #[Test]
    public function an_isolated_include_passing_every_required_variable_is_not_reported(): void
    {
        $this->requireIncludeIsolated();

        $this->assertSame([], $this->templateContractIssues('isolated-complete.blade.php'));
    }

    /** Without an array, `@includeIsolated('view')` compiles to `make('view')`: no data at all. */
    #[Test]
    public function an_isolated_include_without_data_reports_every_required_variable(): void
    {
        $this->requireIncludeIsolated();

        $reported = $this->templateContractIssues('isolated-bare.blade.php');

        $this->assertCount(1, $reported, \var_export($reported, true));
        $this->assertSame(self::MISSING, $reported[0]['type']);
        $this->assertSame(1, $reported[0]['line_from']);
        $this->assertStringContainsString("View 'greeting' declares \$name", (string) $reported[0]['message']);
    }

    #[Test]
    public function an_isolated_include_without_data_of_a_view_with_only_optional_variables_is_not_reported(): void
    {
        $this->requireIncludeIsolated();

        $this->assertSame([], $this->templateContractIssues('isolated-bare-optional.blade.php'));
    }

    /** A closure composer is opaque, so an `@include` value for `composed` is not type-checked. */
    #[Test]
    public function an_include_of_a_composed_view_is_not_type_checked(): void
    {
        $this->assertSame([], $this->templateContractIssues('composed-include.blade.php'));
    }

    /** A closure composer is opaque, so an `@includeIsolated` array omitting `composed`'s variable is not reported. */
    #[Test]
    public function an_isolated_include_of_a_composed_view_is_not_checked_for_presence(): void
    {
        $this->requireIncludeIsolated();

        $this->assertSame([], $this->templateContractIssues('isolated-composed.blade.php'));
    }

    /** `@extends('layout', [...])` compiles to the `@include` call, emitted after the template body. */
    #[Test]
    public function an_extends_value_of_the_wrong_type_is_reported_on_the_directive_line(): void
    {
        $reported = $this->templateContractIssues('extends-data.blade.php');

        $this->assertCount(1, $reported, \var_export($reported, true));
        $this->assertSame(self::WRONG_TYPE, $reported[0]['type']);
        $this->assertSame(2, $reported[0]['line_from']);
    }

    /** The includer's own UndefinedDocblockClass already reports an unknown class; no cascade. */
    #[Test]
    public function an_include_value_of_an_undefined_class_is_not_type_checked(): void
    {
        $this->assertSame([], $this->templateContractIssues('undefined-class.blade.php'));
    }

    #[Test]
    public function an_include_never_reports_unused_view_data(): void
    {
        foreach ($this->fixtureIssues(self::FIXTURE, $this->arguments('psalm-unused-data.xml')) as $issue) {
            $this->assertFalse(
                $issue['type'] === 'UnusedViewData' && \basename((string) $issue['file_name']) === 'extra-key.blade.php',
                \var_export($issue, true),
            );
        }
    }

    /** `@includeIsolated` arrives in Laravel 12.47; below that Blade leaves the directive as text. */
    private function requireIncludeIsolated(): void
    {
        if (!\method_exists(BladeCompiler::class, 'compileIncludeIsolated')) {
            self::markTestSkipped('@includeIsolated requires Laravel >= 12.47.');
        }
    }

    /**
     * @return list<array<array-key, mixed>>
     */
    private function templateContractIssues(string $template): array
    {
        $issues = [];

        foreach ($this->fixtureIssues(self::FIXTURE, $this->arguments('psalm.xml')) as $issue) {
            if (\in_array($issue['type'], [self::MISSING, self::WRONG_TYPE], true) && \basename((string) $issue['file_name']) === $template) {
                $issues[] = $issue;
            }
        }

        return $issues;
    }

    #[Test]
    public function the_check_is_off_unless_the_config_flag_opts_in(): void
    {
        $this->assertSame([], $this->contractIssues('psalm-validation-off.xml'));
    }
}
