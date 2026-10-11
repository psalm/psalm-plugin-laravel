<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

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

    /** A composer or creator can overwrite any key, so a call site is judged on neither presence nor type. */
    #[Test]
    public function a_composed_view_is_checked_for_neither_presence_nor_type(): void
    {
        $issues = $this->contractIssues('psalm.xml');

        $this->assertSame([], $this->forFile($issues, 'ComposedWrongType.php'), \var_export($issues, true));
        $this->assertSame([], $this->forFile($issues, 'ComposedMissing.php'), \var_export($issues, true));
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
     * `@include('view', [...])` hands the partial its literal array AND the including template's
     * scope, so a key absent from the array is only missing when the includer cannot supply it.
     */
    #[Test]
    public function an_include_missing_a_variable_the_includer_cannot_supply_is_reported_on_its_line(): void
    {
        $reported = $this->templateContractIssues('missing.blade.php');

        // Line 5 sits under a Blade suppression of another issue, which the shadow carries as a
        // docblock between the open tag and the echo.
        $this->assertSame([3, 5], \array_column($reported, 'line_from'), \var_export($reported, true));

        foreach ($reported as $issue) {
            $this->assertSame(self::MISSING, $issue['type']);
            $this->assertStringContainsString("View 'inc.card' declares \$count", (string) $issue['message']);
        }
    }

    #[Test]
    public function an_include_whose_includer_declares_or_binds_the_variable_is_not_reported(): void
    {
        $this->assertSame([], $this->templateContractIssues('declared.blade.php'));
        $this->assertSame([], $this->templateContractIssues('declared-required.blade.php'));
        $this->assertSame([], $this->templateContractIssues('bound.blade.php'));
    }

    #[Test]
    public function an_include_value_of_the_wrong_type_is_reported_and_a_mixed_one_declines(): void
    {
        $reported = $this->templateContractIssues('wrong-type.blade.php');

        $this->assertCount(1, $reported, \var_export($reported, true));
        $this->assertSame(self::WRONG_TYPE, $reported[0]['type']);
        $this->assertSame(3, $reported[0]['line_from']);
        $this->assertStringContainsString('$title as string, but int', (string) $reported[0]['message']);
    }

    /** An includer whose scope is dynamic, or that declares no contract at all, may hand on anything. */
    #[Test]
    public function an_include_from_an_open_includer_scope_is_not_reported(): void
    {
        $this->assertSame([], $this->templateContractIssues('dynamic.blade.php'));
        $this->assertSame([], $this->templateContractIssues('no-contract.blade.php'));
    }

    #[Test]
    public function the_other_include_directives_are_not_checked(): void
    {
        $this->assertSame([], $this->templateContractIssues('include-if.blade.php'));
    }

    /** A name only an `@php` assignment binds, never read, is still in the includer's scope. */
    #[Test]
    public function an_include_after_an_assignment_in_the_includer_is_not_reported(): void
    {
        $this->assertSame([], $this->templateContractIssues('assign-block.blade.php'));
        $this->assertSame([], $this->templateContractIssues('assign-paren.blade.php'));
        $this->assertSame([], $this->templateContractIssues('assign-conditional.blade.php'));
    }

    /** An anonymous component's view receives every tag attribute as a variable. */
    #[Test]
    public function an_include_from_an_anonymous_components_view_is_not_reported(): void
    {
        $this->assertSame([], $this->templateContractIssues('anon-includer.blade.php'));
    }

    /**
     * Any by-name render hands the view data its own declarations do not show: `@component`'s
     * named slots, `@each`'s item and key, and the including scope of a slash-spelled `@include`.
     */
    #[Test]
    public function an_include_from_a_view_any_directive_renders_by_name_is_not_reported(): void
    {
        $this->assertSame([], $this->templateContractIssues('slot-panel.blade.php'));
        $this->assertSame([], $this->templateContractIssues('each-panel.blade.php'));
        $this->assertSame([], $this->templateContractIssues('slash-panel.blade.php'));
    }

    /** Only `__data` and `__path` leave the scope `@include` forwards, so an author's `$__x` arrives. */
    #[Test]
    public function an_include_needing_a_double_underscore_name_is_not_reported(): void
    {
        $this->assertSame([], $this->templateContractIssues('dunder.blade.php'));
    }

    /** A class component's view receives `data()`, whether or not its render() shape is typed. */
    #[Test]
    public function an_include_from_a_class_components_view_is_not_reported(): void
    {
        $this->assertSame([], $this->templateContractIssues('component-view.blade.php'));
    }

    /**
     * A template another template includes or extends receives that template's whole scope, so
     * what it hands on is not bounded by its own declarations.
     */
    #[Test]
    public function an_include_from_an_included_or_extended_template_is_not_reported(): void
    {
        $this->assertSame([], $this->templateContractIssues('parent.blade.php'));
        $this->assertSame([], $this->templateContractIssues('layout.blade.php'));
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

    /** The includer's own UndefinedDocblockClass already reports an unknown class; no cascade. */
    #[Test]
    public function an_include_value_of_an_undefined_class_is_not_type_checked(): void
    {
        $this->assertSame([], $this->templateContractIssues('undefined-class.blade.php'));
    }

    /** `@extends('layout', [...])` compiles to the same call, and hands the layout the same scope. */
    #[Test]
    public function an_extends_with_literal_data_is_checked_like_an_include(): void
    {
        $reported = $this->templateContractIssues('extends-data.blade.php');

        $this->assertCount(1, $reported, \var_export($reported, true));
        $this->assertSame(self::MISSING, $reported[0]['type']);
        $this->assertSame(2, $reported[0]['line_from']);
    }

    /** A hand-written call that compiles byte-identically to `@include` has its runtime meaning too. */
    #[Test]
    public function a_handwritten_compiled_include_is_checked_like_the_directive(): void
    {
        $reported = $this->templateContractIssues('handwritten.blade.php');

        $this->assertCount(1, $reported, \var_export($reported, true));
        $this->assertSame(self::MISSING, $reported[0]['type']);
        $this->assertSame(2, $reported[0]['line_from']);
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
