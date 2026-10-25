<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Blade;

use Illuminate\View\Compilers\BladeCompiler;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Blade\ContractRegistry;
use Psalm\LaravelPlugin\Blade\ReadSetResolver;
use Psalm\LaravelPlugin\Handlers\Views\ViewCallChain;
use Psalm\LaravelPlugin\Handlers\Views\ViewContractHandler;
use Psalm\LaravelPlugin\Issues\InvalidViewData;
use Psalm\LaravelPlugin\Issues\MissingViewData;
use Psalm\LaravelPlugin\Issues\UnusedViewData;

/**
 * End-to-end proof of the view-data checks that need a real `vendor/bin/psalm` run over a fixture
 * app, because their inputs only exist after the Blade bootstrap compiled the fixture's templates
 * and Psalm inferred the argument types at each call site:
 *
 *  - a template's `@var` docblock / `@props` contract is enforced at the `view()` call sites that
 *    feed it ({@see MissingViewData}, {@see InvalidViewData}), including the data Laravel and
 *    Livewire add at render time and the `@include` family;
 *  - a data key no template in the include chain reads or declares is reported
 *    ({@see UnusedViewData}; its cases live in `app/UnusedData`).
 *
 * One run serves every case: the fixture holds a file per behavior and each case asserts only the
 * issues of the files it owns. A view name or file basename must therefore stay unique across the
 * fixture. Severity under a bare `<blade />` versus `<experimental />` is pinned in-process by
 * `ExperimentalIssuePolicyTest`.
 *
 * phpt type tests cannot cover this: they analyze against Testbench's view root inside `vendor/`,
 * and no `tests/Type/psalm-*.xml` config enables Blade.
 */
#[CoversClass(ViewContractHandler::class)]
#[CoversClass(ViewCallChain::class)]
#[CoversClass(ContractRegistry::class)]
#[CoversClass(ReadSetResolver::class)]
#[Group('subprocess')]
final class ViewContractValidationTest extends TestCase
{
    use AnalysesFixtureApp;

    private const FIXTURE = __DIR__ . '/Fixtures/ViewContract';

    private const MISSING = 'MissingViewData';

    private const WRONG_TYPE = 'InvalidViewData';

    private const UNUSED = 'UnusedViewData';

    private const TYPE_HINT = " Pass a value of the declared type, or correct the template's declaration.";

    /** `--show-info` keeps the `info` default of the experimental issues in the report. */
    private const ARGUMENTS = ['-c', 'psalm.xml', '--no-cache', '--threads=1', '--no-progress', '--output-format=json', '--show-info=true'];

    /**
     * Every issue of the shared run.
     *
     * @return list<array<string, mixed>>
     */
    private function allIssues(): array
    {
        return $this->fixtureIssues(self::FIXTURE, self::ARGUMENTS);
    }

    /**
     * Issues of the given types only, keyed by the fixture file that caused them: the fixture
     * reports plenty of unrelated issues at errorLevel 1 and the shadows add more.
     *
     * @return list<array{type: string, file: string, message: string}>
     */
    private function issuesOfType(string ...$types): array
    {
        $issues = [];

        foreach ($this->allIssues() as $issue) {
            $type = (string) $issue['type'];

            if (!\in_array($type, $types, true)) {
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

    /** @return list<array{type: string, file: string, message: string}> */
    private function contractIssues(): array
    {
        return $this->issuesOfType(self::MISSING, self::WRONG_TYPE);
    }

    /** @return list<array{type: string, file: string, message: string}> */
    private function unusedIssues(): array
    {
        return $this->issuesOfType(self::UNUSED);
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
        $issues = $this->contractIssues();
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
        $issues = $this->contractIssues();
        $reported = $this->forFile($issues, 'MissingVariable.php');

        $this->assertCount(2, $reported, \var_export($issues, true));

        foreach ($reported as $issue) {
            $this->assertSame(self::MISSING, $issue['type']);
        }

        $messages = \array_column($reported, 'message');
        \sort($messages);

        // The declaring line and the fix hint are part of the message.
        $this->assertSame([
            'View \'profile\' requires $age (int, resources/views/profile.blade.php:2), but this call does not pass \'age\'. '
            . 'Pass it, or mark it optional with "= null" or a @props default.',
            'View \'profile\' requires $name (string, resources/views/profile.blade.php:1), but this call does not pass \'name\'. '
            . 'Pass it, or mark it optional with "= null" or a @props default.',
        ], $messages);
    }

    #[Test]
    public function a_data_value_that_does_not_satisfy_the_declared_type_is_reported(): void
    {
        $issues = $this->contractIssues();
        $reported = $this->forFile($issues, 'WrongType.php');

        $this->assertCount(1, $reported, \var_export($issues, true));
        $this->assertSame(self::WRONG_TYPE, $reported[0]['type']);
        $this->assertSame(
            "View 'greeting' declares \$name as string, but this call passes 123." . self::TYPE_HINT,
            $reported[0]['message'],
        );
    }

    #[Test]
    public function a_printed_type_carries_no_late_static_marker(): void
    {
        $reported = $this->forFile($this->contractIssues(), 'StaticValue.php');

        $this->assertSame([
            "View 'inc.override' declares \$title as int, but this call passes Illuminate\\Database\\Eloquent\\Builder<Illuminate\\Database\\Eloquent\\Model>." . self::TYPE_HINT,
        ], \array_column($reported, 'message'));
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
        $issues = $this->contractIssues();

        $this->assertSame([], $this->forFile($issues, 'RenderableValue.php'), \var_export($issues, true));

        $wrong = $this->forFile($issues, 'NonRenderableValue.php');
        $this->assertCount(2, $wrong, \var_export($issues, true));
        $this->assertSame([self::WRONG_TYPE, self::WRONG_TYPE], \array_column($wrong, 'type'));

        $messages = \implode("\n", \array_column($wrong, 'message'));
        $this->assertStringContainsString('DateTimeImmutable', $messages);
        $this->assertStringContainsString('Illuminate\Contracts\View\View|int', $messages);
    }

    /**
     * `with()` dispatches on `is_array($key)`, never on the argument count. Both halves are pinned
     * together: reading the count instead makes the first case silent AND the second a false
     * positive, and each alone could be explained by an unrelated decline.
     */
    #[Test]
    public function a_single_key_with_call_assigns_null_and_an_array_key_merges(): void
    {
        $issues = $this->contractIssues();

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
        $issues = $this->contractIssues();
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
        $issues = $this->contractIssues();

        $this->assertSame([], $this->forFile($issues, 'ComponentRender.php'), \var_export($issues, true));

        $wrongType = $this->forFile($issues, 'ComponentWrongType.php');
        $this->assertCount(1, $wrongType, \var_export($issues, true));
        $this->assertSame(self::WRONG_TYPE, $wrongType[0]['type']);
        $this->assertStringContainsString('$count', $wrongType[0]['message']);

        // A userland data() can add any key, so the supplied set is open rather than enumerated.
        $this->assertSame([], $this->forFile($issues, 'ComponentDataOverride.php'), \var_export($issues, true));
    }

    /**
     * `createVariableFromMethod()` hands a method with parameters to the view as
     * `Closure::fromCallable()`, so the key carries the method's own signature: a declaration that
     * matches it passes, a mismatched one is reported, and a generic method is `mixed`, so its declaration is not checked.
     */
    #[Test]
    public function a_component_method_with_parameters_is_typed_as_its_closure_signature(): void
    {
        $issues = $this->forFile($this->contractIssues(), 'ComponentMethods.php');
        $messages = \array_column($issues, 'message');
        \sort($messages);

        $this->assertSame([
            "View 'component-methods' declares \$label as \\Closure(int): string, but this call passes Closure[impure](string):string." . self::TYPE_HINT,
        ], $messages);
    }

    /**
     * `self` and `parent` in a method inherited from a parent class, or brought in by that parent's
     * trait, resolve against the class the method appears in, not the rendered component.
     */
    #[Test]
    public function an_inherited_component_methods_self_and_parent_name_its_appearing_class(): void
    {
        $issues = $this->forFile($this->contractIssues(), 'ComponentInherited.php');
        $messages = \array_column($issues, 'message');
        \sort($messages);

        $this->assertSame([
            "View 'component-inherited' declares \$retParent as \\Closure(int): \\ViewContractFixture\\VcBase, but this call passes Closure[impure](int):Illuminate\\View\\Component." . self::TYPE_HINT,
            "View 'component-inherited' declares \$traitParent as \\Closure(int): \\ViewContractFixture\\VcBase, but this call passes Closure[impure](int):Illuminate\\View\\Component." . self::TYPE_HINT,
            "View 'component-inherited' declares \$traitSelf as \\Closure(int): \\ViewContractFixture\\ComponentInherited, but this call passes Closure[impure](int):ViewContractFixture\\VcBase." . self::TYPE_HINT,
        ], $messages);
    }

    /**
     * A public method with no parameters reaches the view as an `InvokableComponentVariable` of
     * its return type, so `@var InvokableComponentVariable<TValue>` both satisfies the check and keeps
     * `$name()` typed. A mismatched `TValue` still reports. An untyped or generic method is `mixed`, so
     * its declaration is not checked, and the bare class name accepts any `TValue`.
     */
    #[Test]
    public function a_no_parameter_method_is_an_invokable_variable_of_its_return_type(): void
    {
        $issues = $this->contractIssues();
        $invokable = $this->forFile($issues, 'ComponentInvokable.php');

        $this->assertSame([
            'View \'component-invokable\' declares $label as \Illuminate\View\InvokableComponentVariable<int>, but this call passes Illuminate\View\InvokableComponentVariable<string>.' . self::TYPE_HINT,
        ], \array_column($invokable, 'message'), \var_export($issues, true));
        $this->assertSame([self::WRONG_TYPE], \array_column($invokable, 'type'));

        $traces = [];
        $templateIssues = [];

        foreach ($this->allIssues() as $issue) {
            if (\str_ends_with((string) $issue['file_name'], 'component-invokable.blade.php')) {
                $issue['type'] === 'Trace'
                    ? $traces[] = (string) $issue['message']
                    : $templateIssues[] = $issue['type'] . ': ' . $issue['message'];
            }
        }

        $this->assertSame(['$resolved: list{int, string}'], $traces);
        $this->assertSame([], $templateIssues);
    }

    /**
     * `renderComponent()` ends in `$view->with($componentData)`, which merges last, so a public
     * property overrides the same key passed explicitly from `render()`.
     */
    #[Test]
    public function a_class_components_data_overrides_the_same_key_passed_from_render(): void
    {
        $issues = $this->contractIssues();

        $this->assertSame([], $this->forFile($issues, 'ComponentOverlappingKey.php'), \var_export($issues, true));
    }

    #[Test]
    public function a_mailable_view_chain_resolves_through_its_userland_subclass(): void
    {
        $issues = $this->contractIssues();
        $reported = $this->forFile($issues, 'MailableChain.php');

        $this->assertCount(1, $reported, \var_export($issues, true));
        $this->assertSame(self::WRONG_TYPE, $reported[0]['type']);
    }

    /**
     * @param list<array{type: string, file: string, message: string}> $issues
     *
     * @return list<string> `Type $variable` per issue, sorted
     */
    private function variablesOf(array $issues): array
    {
        $variables = \array_map(
            static fn(array $issue): string => $issue['type'] . ' ' . (\preg_match('/(?:declares|requires) (\\$\w+)/', $issue['message'], $m) === 1 ? $m[1] : ''),
            $issues,
        );
        \sort($variables);

        return $variables;
    }

    /**
     * `Mailable::buildViewData()` merges every public property (statics included) and `Mailer`
     * adds `$message`, so none of them is missing; the one name nothing supplies still is.
     */
    #[Test]
    public function a_mailables_public_properties_and_message_count_as_supplied(): void
    {
        $issues = $this->contractIssues();

        $this->assertSame([self::MISSING . ' $absent'], $this->variablesOf($this->forFile($issues, 'MailableProps.php')), \var_export($issues, true));
    }

    /** The receiver's class supplies the properties; a receiver typed as the base class proves nothing. */
    #[Test]
    public function an_external_mailable_view_call_reads_the_receivers_class_and_declines_on_the_base_class(): void
    {
        $reported = $this->forFile($this->contractIssues(), 'MailableExternal.php');

        $this->assertSame([self::MISSING . ' $absent'], $this->variablesOf($reported), \var_export($reported, true));
        $this->assertStringContainsString("View 'mail-external'", $reported[0]['message']);
    }

    #[Test]
    public function a_mailable_property_is_checked_against_its_declared_type(): void
    {
        $reported = $this->forFile($this->contractIssues(), 'MailableWrongType.php');

        $this->assertSame([self::WRONG_TYPE . ' $order'], $this->variablesOf($reported), \var_export($reported, true));
        $this->assertStringContainsString('but this call passes ViewContractFixture\Thing.', $reported[0]['message']);
    }

    /**
     * A key the framework supplies was never passed, so it is never unused: only the `with()` key
     * is, and `unread`, `note` and the rest are not.
     */
    #[Test]
    public function a_framework_supplied_key_is_never_reported_as_unused(): void
    {
        $unused = \array_values(\array_filter(
            $this->unusedIssues(),
            static fn(array $issue): bool => \in_array($issue['file'], ['MailableProps.php', 'NotificationMail.php', 'LivewireCounter.php'], true),
        ));

        $this->assertCount(1, $unused, \var_export($unused, true));
        $this->assertStringContainsString("passes 'extra'", $unused[0]['message']);
    }

    /**
     * `MailMessage::data()` is `array_merge(toArray(), viewData)`: the `toArray()` keys and
     * `$message` are supplied, a passed key wins over its `toArray()` twin (an `int` greeting
     * satisfies `@var int`), and a subclass overriding `toArray()` leaves the key set open.
     */
    #[Test]
    public function a_mail_messages_array_keys_and_message_count_as_supplied(): void
    {
        $reported = $this->forFile($this->contractIssues(), 'NotificationMail.php');

        $this->assertSame([self::MISSING . ' $absent'], $this->variablesOf($reported), \var_export($reported, true));
        $this->assertStringContainsString("View 'mail-notification'", $reported[0]['message']);
    }

    /**
     * The vendor property docblocks (`array`, `string`) are coarser than the values a MailMessage
     * carries, so its `toArray()` keys are exempt from the type check: `list<string>`, a literal
     * union and `string|null` declarations are not reported.
     */
    #[Test]
    public function a_mail_messages_array_keys_are_not_type_checked(): void
    {
        $issues = $this->contractIssues();
        $narrow = \array_filter($this->forFile($issues, 'NotificationMail.php'), static fn(array $issue): bool => \str_contains($issue['message'], 'mail-notification-narrow'));

        $this->assertSame([], $narrow, \var_export($issues, true));
    }

    /**
     * `markdown()` merges the view data after the injected `$message`, and an uninitialized property
     * leaves a passed value in place: in both cases the key may hold what the call passed.
     */
    #[Test]
    public function a_mailable_key_the_call_may_pass_is_not_type_checked(): void
    {
        $issues = $this->contractIssues();

        $this->assertSame([], $this->forFile($issues, 'MailableOpenData.php'), \var_export($issues, true));
        $this->assertSame([], $this->forFile($issues, 'MailableFallback.php'), \var_export($issues, true));
    }

    /**
     * A userland `buildViewData()` or `additionalMessageData()` can add, drop or change any key:
     * nothing is typed, nothing is missing, and a passed key loses its passed type too. The passed
     * but unread key is still unused, as framework entries never touch what was passed.
     */
    #[Test]
    public function a_mailable_overriding_its_view_data_leaves_the_key_set_open(): void
    {
        $issues = $this->contractIssues();

        $this->assertSame([], $this->forFile($issues, 'MailableOverride.php'), \var_export($issues, true));
        $this->assertSame([], $this->forFile($issues, 'MailableAdditional.php'), \var_export($issues, true));

        $unused = $this->forFile($this->unusedIssues(), 'MailableAdditional.php');

        $this->assertCount(1, $unused, \var_export($unused, true));
        $this->assertStringContainsString("passes 'junk'", $unused[0]['message']);
    }

    /**
     * `Mailer::send()` and `render()` set `message` after every merge, so on the `view()` path the
     * template gets the `Message` object whatever a property or a `toArray()` override says. Only
     * the markdown renderers merge the view data over it, so there a property wins.
     */
    #[Test]
    public function the_message_key_loses_to_a_property_on_the_markdown_path_only(): void
    {
        $issues = $this->contractIssues();

        foreach (['MailableMessageProperty.php', 'MailMessageOverrideTypes.php'] as $file) {
            $reported = $this->forFile($issues, $file);

            $this->assertSame([self::WRONG_TYPE . ' $message'], $this->variablesOf($reported), $file . \var_export($issues, true));
            $this->assertStringContainsString('declares $message as string, but this call passes Illuminate\Mail\Message.', $reported[0]['message'], $file);
        }
    }

    /**
     * `Mailable::buildMarkdownText()` wraps `message` in `TextMessage` for the text pass, so a markdown
     * Mailable's template receives either object. `MailChannel` and the `view()` path hand it the
     * `Message` alone.
     */
    #[Test]
    public function a_markdown_mailables_message_is_a_message_or_a_text_message(): void
    {
        $issues = $this->contractIssues();
        $reported = $this->forFile($issues, 'MailableMarkdownMessage.php');

        $this->assertSame([self::WRONG_TYPE . ' $message'], $this->variablesOf($reported), \var_export($issues, true));
        $this->assertStringContainsString('declares $message as \Illuminate\Mail\Message, but this call passes Illuminate\Mail\Message|Illuminate\Mail\TextMessage.', $reported[0]['message']);
        $this->assertSame([], $this->forFile($issues, 'MailMessageMarkdownMessage.php'), \var_export($issues, true));
    }

    /**
     * A `message` the call passes is replaced by the Mailer on the `view()` path and kept by the
     * markdown renderer, so only there is the passed value checked.
     */
    #[Test]
    public function a_passed_message_is_checked_on_the_markdown_path_only(): void
    {
        $issues = $this->contractIssues();
        $reported = $this->forFile($issues, 'MailableMessagePassed.php');

        $this->assertSame([self::WRONG_TYPE . ' $message'], $this->variablesOf($reported), \var_export($issues, true));
        $this->assertStringContainsString('declares $message as \Illuminate\Mail\Message, but this call passes ', $reported[0]['message']);
        $this->assertStringNotContainsString('passes Illuminate\Mail\Message', $reported[0]['message']);
    }

    /**
     * A subclass of an abstract Mailable or Livewire component can add public properties the base
     * does not declare, so a variable no property supplies is not provably absent.
     */
    #[Test]
    public function an_abstract_base_leaves_the_key_set_open(): void
    {
        $issues = $this->contractIssues();

        $this->assertSame([], $this->forFile($issues, 'MailableBase.php'), \var_export($issues, true));
        $this->assertSame([], $this->forFile($issues, 'LivewireBase.php'), \var_export($issues, true));
    }

    /**
     * A look-alike class with the same public property and `render()` is not a framework subclass,
     * so the framework merges nothing into its view and every variable stays missing.
     */
    #[Test]
    public function a_class_that_merely_looks_like_a_mailable_or_component_supplies_nothing(): void
    {
        $issues = $this->contractIssues();

        $this->assertSame([self::MISSING . ' $absent', self::MISSING . ' $title'], $this->variablesOf($this->forFile($issues, 'NotAMailable.php')), \var_export($issues, true));
        $this->assertSame([self::MISSING . ' $absent', self::MISSING . ' $count'], $this->variablesOf($this->forFile($issues, 'NotLivewire.php')), \var_export($issues, true));
    }

    /**
     * Only a public property reaches a Mailable's view, and `ReflectionClass::getProperties()` lists
     * a public static one too.
     */
    #[Test]
    public function only_public_mailable_properties_are_supplied_and_statics_count(): void
    {
        $reported = $this->forFile($this->contractIssues(), 'MailableVisibility.php');

        $this->assertSame([self::MISSING . ' $priv', self::MISSING . ' $prot'], $this->variablesOf($reported), \var_export($reported, true));
    }

    /**
     * Livewire merges a component's properties into the view `render()` returns and nothing else, so
     * a `MailMessage` view built on the side inside `render()` still lacks `$count` while the
     * returned view has it.
     */
    #[Test]
    public function livewire_properties_reach_only_the_view_render_returns(): void
    {
        $issues = $this->contractIssues();
        $reported = $this->forFile($issues, 'LivewireForeignView.php');

        $this->assertSame([self::MISSING . ' $count'], $this->variablesOf($reported), \var_export($issues, true));
        $this->assertStringContainsString("View 'livewire.placeholder' requires \$count", $reported[0]['message']);
    }

    /**
     * #2043: `parent` in a class component's property type names the class declaring the property, and a
     * trait's `self` the class using the trait, not the component the view renders for.
     */
    #[Test]
    public function a_component_properties_self_and_parent_name_their_declaring_class(): void
    {
        $reported = $this->forFile($this->contractIssues(), 'ComponentOwner.php');

        $this->assertSame([self::WRONG_TYPE . ' $owner'], $this->variablesOf($reported), \var_export($reported, true));
        $this->assertStringContainsString('but this call passes Illuminate\View\Component.', $reported[0]['message']);
    }

    /**
     * `forSource()` ignores a userland `$except` for presence (a superset), but a property it names keeps
     * the value the call passes: the class's own type for it must not replace that value. A property not
     * named still reports, and a list Psalm cannot read (a `@var`-typed default) drops every property type.
     */
    #[Test]
    public function an_excepted_property_keeps_the_value_the_call_passes(): void
    {
        $issues = $this->contractIssues();
        $excepted = $this->forFile($issues, 'ComponentExcepted.php');

        $this->assertSame([self::WRONG_TYPE . ' $count'], $this->variablesOf($excepted), \var_export($issues, true));
        $this->assertSame([], $this->forFile($issues, 'ComponentExceptedOpaque.php'), \var_export($issues, true));
    }

    /**
     * Livewire renders `render()`'s view with the component's public, non-static properties. Every
     * half is asserted together: a protected property, a private one, a static one and a view built
     * outside `render()` are still missing.
     */
    #[Test]
    public function a_livewire_components_public_properties_count_as_supplied_in_render(): void
    {
        $issues = $this->contractIssues();

        $this->assertSame(
            [self::MISSING . ' $count', self::MISSING . ' $hidden', self::MISSING . ' $instances', self::MISSING . ' $secret'],
            $this->variablesOf($this->forFile($issues, 'LivewireCounter.php')),
            \var_export($issues, true),
        );
        $this->assertStringContainsString("View 'livewire.placeholder' requires \$count", \implode(' ', \array_column($this->forFile($issues, 'LivewireCounter.php'), 'message')));
    }

    /**
     * A `@props` entry with a literal default is optional and one without is not. Both halves are
     * asserted together: a template whose props failed to parse would be silent for BOTH, and the
     * optional case alone could not tell that apart from the gate working.
     */
    #[Test]
    public function only_the_props_entry_without_a_default_is_required(): void
    {
        $issues = $this->contractIssues();

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
        $issues = $this->contractIssues();

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
        $issues = $this->contractIssues();
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
        $issues = $this->contractIssues();

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
        $issues = $this->contractIssues();

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
        $issues = $this->contractIssues();
        $reported = $this->forFile($issues, 'NamedArguments.php');

        $this->assertCount(2, $reported, \var_export($issues, true));

        foreach ($reported as $issue) {
            $this->assertSame(self::WRONG_TYPE, $issue['type']);
        }
    }

    /** Each of these pins a different decline gate, with everything else about the call held equal. */
    #[Test]
    public function every_decline_gate_stays_silent(): void
    {
        $issues = $this->contractIssues();

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
        $issues = $this->contractIssues();

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
    public function a_raw_var_for_a_blade_owned_name_or_in_a_plain_comment_is_no_contract(): void
    {
        $this->assertSame([], $this->forFile($this->contractIssues(), 'RawNotContracts.php'));
    }

    /** The template's own `use` alias is invisible to the contract: the type check declines, the presence check stays. */
    #[Test]
    public function a_short_class_name_declines_the_type_check_but_keeps_the_presence_check(): void
    {
        $issues = $this->contractIssues();

        // Only `missing()` reports; `render()` passes a value the alias would have to resolve.
        $this->assertSame([self::MISSING], \array_column($this->forFile($issues, 'RawShortName.php'), 'type'), \var_export($issues, true));
    }

    #[Test]
    public function a_components_public_render_leaves_the_data_set_open(): void
    {
        $this->assertSame([], $this->forFile($this->contractIssues(), 'ComponentRenderPublic.php'));
    }

    /**
     * #2079: a named function or a private/protected method that returns a bare view is a helper its
     * caller completes (`helper()->with('name', $n)`), so the data set seen at the `return` is open.
     */
    #[Test]
    public function a_view_returned_by_a_non_public_helper_is_not_reported_missing(): void
    {
        $this->assertSame([], $this->forFile($this->contractIssues(), 'NonPublicViewHelpers.php'));
    }

    #[Test]
    public function a_non_public_helper_still_has_its_passed_values_type_checked(): void
    {
        $reported = $this->forFile($this->contractIssues(), 'NonPublicViewHelperWrongType.php');

        $this->assertSame([self::WRONG_TYPE], \array_column($reported, 'type'), \var_export($reported, true));
    }

    /**
     * A public method (a controller action) and a closure (a route closure) are the final handler:
     * only a non-public helper or a named function is exempt, even a closure inside one.
     */
    #[Test]
    public function a_view_returned_by_a_public_method_or_a_closure_is_still_reported_missing(): void
    {
        $issues = $this->contractIssues();

        $this->assertSame(
            [self::MISSING . ' $name', self::MISSING . ' $name', self::MISSING . ' $name'],
            $this->variablesOf($this->forFile($issues, 'PublicViewReturners.php')),
            \var_export($issues, true),
        );
    }

    #[Test]
    public function a_raw_optional_marker_on_a_type_without_null_is_reported_at_the_declaration(): void
    {
        $reported = [];

        foreach ($this->allIssues() as $issue) {
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

    /** A closure composer is opaque, so its view is exempt from the presence and the type check alike. */
    #[Test]
    public function a_composed_view_is_not_checked(): void
    {
        $issues = $this->contractIssues();

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

        foreach ($this->forFile($this->contractIssues(), 'ComposerCallSites.php') as $issue) {
            \preg_match("/View '([^']+)' (?:declares|requires) \\$(\\w+)/", $issue['message'], $matches);
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
        $issues = $this->contractIssues();

        $this->assertSame([], $this->forFile($issues, 'RawScope.php'), \var_export($issues, true));

        foreach ($this->allIssues() as $issue) {
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
            $this->assertStringContainsString('$title as string, but this call passes int.', (string) $issue['message']);
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
        $this->assertStringContainsString("View 'inc.card' requires \$count", (string) $reported[0]['message']);
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
        $this->assertStringContainsString("View 'greeting' requires \$name", (string) $reported[0]['message']);
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
        $this->assertSame([], $this->forFile($this->unusedIssues(), 'extra-key.blade.php'));
    }

    /**
     * `@includeWhen`/`@includeUnless` compile to `renderWhen()`/`renderUnless()` and `@includeFirst`
     * to `first()->render()`, all with the includer's scope as `$mergeData`. `@includeFirst` checks
     * the first candidate that exists, as `Factory::first()` renders it.
     */
    #[Test]
    public function a_conditional_or_first_include_value_of_the_wrong_type_is_reported_on_its_line(): void
    {
        $reported = $this->templateContractIssues('conditional-wrong-type.blade.php');

        $this->assertSame([2, 3, 4, 5], \array_column($reported, 'line_from'), \var_export($reported, true));

        foreach ($reported as $issue) {
            $this->assertSame(self::WRONG_TYPE, $issue['type']);
            $this->assertStringContainsString("View 'inc.card' declares \$title as string, but this call passes int.", (string) $issue['message']);
        }
    }

    /** Each pins a different decline gate of the conditional and first include forms. */
    #[Test]
    public function every_conditional_or_first_include_decline_gate_stays_silent(): void
    {
        // `inc.override` exists, so it renders, and it declares `$title` as int.
        $this->assertSame([], $this->templateContractIssues('first-existing-candidate.blade.php'));
        // A dynamic, namespaced or keyed candidate before `inc.card` may be the one that renders; so
        // may one Laravel trims, and a keyed one after it can overwrite it.
        $this->assertSame([], $this->templateContractIssues('first-unprovable-candidate.blade.php'));
        // Data in a variable: a sealed `array{title: int}` would report if it were read.
        $this->assertSame([], $this->templateContractIssues('conditional-dynamic-data.blade.php'));
        $this->assertSame([], $this->templateContractIssues('conditional-dynamic-name.blade.php'));
        // The includer's scope is `$mergeData`, so the omitted `count` may still arrive; an authored
        // `first()` without it is not closed either.
        $this->assertSame([], $this->templateContractIssues('conditional-omitted.blade.php'));
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

        foreach ($this->allIssues() as $issue) {
            if (\in_array($issue['type'], [self::MISSING, self::WRONG_TYPE], true) && \basename((string) $issue['file_name']) === $template) {
                $issues[] = $issue;
            }
        }

        return $issues;
    }

    /**
     * A `loadViewsFrom()` hint outside vendor/ is compiled under its qualified `pkg::name`, so every
     * binder that names it is checked like a default-root view.
     */
    #[Test]
    public function a_namespaced_view_is_checked_through_every_binder(): void
    {
        $issues = $this->contractIssues();

        // In method order: helper, facade, response, mailMessage; correct() is silent.
        $this->assertSame(
            [self::WRONG_TYPE . ' $title', self::MISSING . ' $count', self::WRONG_TYPE . ' $count', self::WRONG_TYPE . ' $title'],
            \array_map(
                static fn(array $issue): string => $issue['type'] . ' ' . (\preg_match('/(?:declares|requires) (\\$\w+)/', $issue['message'], $m) === 1 ? $m[1] : ''),
                $this->forFile($issues, 'NamespacedViews.php'),
            ),
            \var_export($issues, true),
        );
        $this->assertSame([self::WRONG_TYPE], \array_column($this->forFile($issues, 'NamespacedMailable.php'), 'type'), \var_export($issues, true));

        $included = $this->templateContractIssues('namespaced-include.blade.php');
        $this->assertSame([self::WRONG_TYPE], \array_column($included, 'type'), \var_export($included, true));
        $this->assertStringContainsString("View 'pkg::widget' declares \$title as string", (string) $included[0]['message']);
    }

    /**
     * `Markdown::render()`/`renderText()` swap the `mail` namespace for the markdown component paths,
     * so a `markdown('mail::…')` never renders the boot-time `mail` hint; a plain render does.
     */
    #[Test]
    public function a_markdown_name_in_the_mail_namespace_is_not_checked(): void
    {
        $issues = $this->forFile($this->contractIssues(), 'MailNamespaceMarkdown.php');

        $this->assertSame([self::MISSING], \array_column($issues, 'type'), \var_export($issues, true));
    }

    /** `ViewName::normalize()` turns `/` into `.` before the finder runs. */
    #[Test]
    public function a_slashed_view_name_is_checked_under_its_dotted_name(): void
    {
        $issues = $this->forFile($this->contractIssues(), 'ViewNameSpellings.php');

        $this->assertSame([self::WRONG_TYPE], \array_column($issues, 'type'), \var_export($issues, true));
        $this->assertStringContainsString("View 'inc.card' declares \$title", $issues[0]['message']);
    }

    /** An unknown hint and a vendor/ hint never reach the registry, so their call sites stay silent. */
    #[Test]
    public function an_unknown_or_vendor_namespace_is_never_checked(): void
    {
        foreach ($this->allIssues() as $issue) {
            $this->assertFalse(
                \in_array($issue['type'], [self::MISSING, self::WRONG_TYPE, self::UNUSED], true)
                    && (\str_contains((string) $issue['message'], "'nope::") || \str_contains((string) $issue['message'], "'notifications::")),
                \var_export($issue, true),
            );
        }
    }

    /**
     * @param list<array{type: string, file: string, message: string}> $issues
     */
    private function assertReportsOnly(array $issues, string $file, string $key): void
    {
        $reported = $this->forFile($issues, $file);

        $this->assertCount(1, $reported, \var_export($issues, true));
        $this->assertStringContainsString("'{$key}'", $reported[0]['message']);
    }

    #[Test]
    public function a_passed_key_the_template_never_reads_is_reported(): void
    {
        $issues = $this->unusedIssues();
        $this->assertReportsOnly($issues, 'UnreadKey.php', 'orphan');

        $this->assertSame(
            "View 'simple' never reads \$orphan, but this call passes 'orphan'. "
            . 'Remove the key, or declare it with @var if the template needs it.',
            $this->forFile($issues, 'UnreadKey.php')[0]['message'],
        );
    }

    /** 'deep' is read by the included partial and 'veryDeep' by the partial's own include: only 'orphan' is left over. */
    #[Test]
    public function a_key_read_anywhere_down_a_literal_include_chain_is_not_reported(): void
    {
        $this->assertReportsOnly($this->unusedIssues(), 'IncludeChain.php', 'orphan');
    }

    #[Test]
    public function an_include_cycle_terminates_and_still_reports(): void
    {
        $this->assertReportsOnly($this->unusedIssues(), 'IncludeCycle.php', 'orphan');
    }

    /** Each of these leaves the chain's read set a lower bound, which proves no key unused. */
    #[Test]
    public function an_unprovable_read_set_stays_silent(): void
    {
        $issues = $this->unusedIssues();

        // An `@include($name)` leaves the chain's read set a lower bound.
        $this->assertSame([], $this->forFile($issues, 'DynamicInclude.php'), \var_export($issues, true));
        // `@props` (like `@aware`) compiles to `$$__key` writes, which make the read set unknowable.
        $this->assertSame([], $this->forFile($issues, 'PropsPanel.php'), \var_export($issues, true));
        // A shadow the plugin cannot parse says nothing about what the template reads.
        $this->assertSame([], $this->forFile($issues, 'BrokenPhp.php'), \var_export($issues, true));
    }

    /**
     * Blade injects these itself, so a call site passing one is never wrong about the template: the
     * read-set extraction strips them, which is exactly why the emit side has to re-check them.
     */
    #[Test]
    public function an_ambient_key_is_never_reported(): void
    {
        $issues = $this->unusedIssues();

        $this->assertSame([], $this->forFile($issues, 'AmbientKeys.php'), \var_export($issues, true));
    }

    /**
     * A declared key is part of the template's contract whether or not its body reads it, at the top
     * of the include chain and in a partial alike. `UnreadKey.php` proves the rule still fires for a
     * key declared nowhere.
     */
    #[Test]
    public function a_declared_key_is_not_reported_even_when_unread(): void
    {
        $issues = $this->unusedIssues();

        $this->assertSame([], $this->forFile($issues, 'DeclaredButUnread.php'), \var_export($issues, true));
        $this->assertSame([], $this->forFile($issues, 'DeclaredInInclude.php'), \var_export($issues, true));
    }

    /**
     * Loop aliases are dropped from the read set template-wide (a documented ContractParser gap), so
     * they are added back for this rule: over-counting a name as read is the safe direction.
     */
    #[Test]
    public function a_loop_alias_name_is_treated_as_read(): void
    {
        $issues = $this->unusedIssues();

        $this->assertSame([], $this->forFile($issues, 'LoopAlias.php'), \var_export($issues, true));
    }

    /**
     * `@includeIsolated` and `@each` render their template with a fresh scope, so neither may launder
     * a key into the caller's read set.
     */
    #[Test]
    public function a_scope_isolating_directive_does_not_launder_its_read_set(): void
    {
        $issues = $this->unusedIssues();

        $this->assertReportsOnly($issues, 'IsolatedInclude.php', 'secret');
        $this->assertReportsOnly($issues, 'EachHost.php', 'cell');
    }

    #[Test]
    public function a_suppression_in_the_calling_file_silences_it(): void
    {
        $issues = $this->unusedIssues();

        $this->assertSame([], $this->forFile($issues, 'Suppressed.php'), \var_export($issues, true));
    }
}
