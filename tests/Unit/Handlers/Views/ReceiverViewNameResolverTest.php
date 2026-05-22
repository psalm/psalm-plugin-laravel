<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Views;

use PhpParser\Node\Arg;
use PhpParser\Node\ArrayItem;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\NullsafeMethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Views\ReceiverViewNameResolver;

/**
 * Direct AST-level coverage for the receiver-walk extracted from
 * {@see \Psalm\LaravelPlugin\Handlers\Views\BladeAwareViewTaintHandler}. The
 * handler tests in {@see BladeAwareViewTaintHandlerTest} exercise the same
 * resolver indirectly through dispatch; these tests pin the resolution table
 * head-on, so a regression in shape-matching surfaces here before the
 * handler-level taint tests start drifting.
 *
 * Every case constructs a synthetic AST node and asserts on the resolver's
 * single-string return. No Psalm process is spawned.
 */
#[CoversClass(ReceiverViewNameResolver::class)]
final class ReceiverViewNameResolverTest extends TestCase
{
    #[Test]
    public function resolves_view_helper_literal(): void
    {
        $node = $this->funcCall('view', [$this->string('home')]);

        $this->assertSame('home', ReceiverViewNameResolver::resolve($node));
    }

    #[Test]
    public function refuses_view_helper_with_variable_argument(): void
    {
        $node = $this->funcCall('view', [new Arg(new Variable('dynamic'))]);

        $this->assertNull(ReceiverViewNameResolver::resolve($node));
    }

    #[Test]
    public function refuses_other_function_helpers(): void
    {
        $node = $this->funcCall('notview', [$this->string('home')]);

        $this->assertNull(ReceiverViewNameResolver::resolve($node));
    }

    #[Test]
    public function resolves_view_helper_via_chained_with(): void
    {
        $inner = $this->funcCall('view', [$this->string('profile')]);
        $chain = new MethodCall($inner, new Identifier('with'), [$this->string('a'), $this->int(1)]);

        $this->assertSame('profile', ReceiverViewNameResolver::resolve($chain));
    }

    #[Test]
    public function resolves_view_helper_via_double_chained_with(): void
    {
        // view('home')->with('a', 1)->with('b', 2) — recursion depth > 1.
        $inner = $this->funcCall('view', [$this->string('home')]);
        $mid = new MethodCall($inner, new Identifier('with'), [$this->string('a'), $this->int(1)]);
        $outer = new MethodCall($mid, new Identifier('with'), [$this->string('b'), $this->int(2)]);

        $this->assertSame('home', ReceiverViewNameResolver::resolve($outer));
    }

    #[Test]
    public function resolves_chained_witherrors(): void
    {
        // Chain-preserving allowlist also covers `withErrors()` (and similar
        // builder identity-preserving methods).
        $inner = $this->funcCall('view', [$this->string('login')]);
        $chain = new MethodCall($inner, new Identifier('withErrors'), [$this->string('a')]);

        $this->assertSame('login', ReceiverViewNameResolver::resolve($chain));
    }

    #[Test]
    public function resolves_method_call_make_literal(): void
    {
        // $factory->make('home')
        $node = new MethodCall(new Variable('factory'), new Identifier('make'), [$this->string('home')]);

        $this->assertSame('home', ReceiverViewNameResolver::resolve($node));
    }

    #[Test]
    public function refuses_method_call_make_with_variable_argument(): void
    {
        $node = new MethodCall(
            new Variable('factory'),
            new Identifier('make'),
            [new Arg(new Variable('dynamic'))],
        );

        $this->assertNull(ReceiverViewNameResolver::resolve($node));
    }

    #[Test]
    public function refuses_method_call_unsupported_name(): void
    {
        // Method names outside the allowlist do not recurse — `share()`
        // returns null so the dispatcher falls back to the whole-data sink.
        $node = new MethodCall(new Variable('factory'), new Identifier('share'), [$this->string('a')]);

        $this->assertNull(ReceiverViewNameResolver::resolve($node));
    }

    #[Test]
    public function resolves_static_call_make(): void
    {
        // \View::make('home')
        $node = new StaticCall(new Name('View'), new Identifier('make'), [$this->string('home')]);

        $this->assertSame('home', ReceiverViewNameResolver::resolve($node));
    }

    #[Test]
    public function resolves_static_call_first_single_literal(): void
    {
        $node = new StaticCall(new Name('View'), new Identifier('first'), [
            new Arg(new Array_([new ArrayItem($this->string('home')->value)])),
        ]);

        $this->assertSame('home', ReceiverViewNameResolver::resolve($node));
    }

    #[Test]
    public function refuses_static_call_first_with_multiple_literals(): void
    {
        // Laravel's runtime picks the first existing view; analysis-time we
        // cannot tell, so the resolver refuses both. See class docblock on
        // ReceiverViewNameResolver::firstLiteralFromArrayArg() for soundness.
        $node = new StaticCall(new Name('View'), new Identifier('first'), [
            new Arg(new Array_([
                new ArrayItem($this->string('safe_layout')->value),
                new ArrayItem($this->string('unsafe_show')->value),
            ])),
        ]);

        $this->assertNull(ReceiverViewNameResolver::resolve($node));
    }

    #[Test]
    public function refuses_static_call_first_with_non_literal_element(): void
    {
        $node = new StaticCall(new Name('View'), new Identifier('first'), [
            new Arg(new Array_([
                new ArrayItem($this->string('home')->value),
                new ArrayItem(new Variable('dynamic')),
            ])),
        ]);

        $this->assertNull(ReceiverViewNameResolver::resolve($node));
    }

    #[Test]
    public function refuses_static_call_first_with_non_array_argument(): void
    {
        $node = new StaticCall(
            new Name('View'),
            new Identifier('first'),
            [new Arg(new Variable('views'))],
        );

        $this->assertNull(ReceiverViewNameResolver::resolve($node));
    }

    #[Test]
    public function refuses_static_call_unsupported_method(): void
    {
        $node = new StaticCall(new Name('View'), new Identifier('share'), [$this->string('a')]);

        $this->assertNull(ReceiverViewNameResolver::resolve($node));
    }

    #[Test]
    public function refuses_static_call_dynamic_method_name(): void
    {
        // \View::{$method}('home') — method name is an expression, not an
        // Identifier. Resolver bails out.
        $node = new StaticCall(new Name('View'), new Variable('method'), [$this->string('home')]);

        $this->assertNull(ReceiverViewNameResolver::resolve($node));
    }

    #[Test]
    public function resolves_nullsafe_method_call_make_literal(): void
    {
        // $factory?->make('home')
        $node = new NullsafeMethodCall(
            new Variable('factory'),
            new Identifier('make'),
            [$this->string('home')],
        );

        $this->assertSame('home', ReceiverViewNameResolver::resolve($node));
    }

    #[Test]
    public function refuses_nullsafe_method_call_unsupported_name(): void
    {
        $node = new NullsafeMethodCall(
            new Variable('factory'),
            new Identifier('share'),
            [$this->string('a')],
        );

        $this->assertNull(ReceiverViewNameResolver::resolve($node));
    }

    #[Test]
    public function refuses_bare_variable_receiver(): void
    {
        // The driving motivation for not supporting variable-bound chains in
        // PR-5: $v = view('home'); $v->with(...). Resolver hits the Variable
        // receiver at the bottom of the recursion and returns null.
        $node = new MethodCall(new Variable('v'), new Identifier('with'), [$this->string('a')]);

        $this->assertNull(ReceiverViewNameResolver::resolve($node));
    }

    #[Test]
    public function refuses_view_helper_via_dynamic_function_name(): void
    {
        // ${$fn}('home') — function name is an expression, not a Name node.
        $node = new FuncCall(new Variable('fn'), [$this->string('home')]);

        $this->assertNull(ReceiverViewNameResolver::resolve($node));
    }

    #[Test]
    public function resolves_method_call_first_single_literal(): void
    {
        // $factory->first(['home']) — the MethodCall counterpart to the
        // StaticCall coverage. Guards against a regression that drops the
        // `first` arm in resolveMethodCallReceiver while leaving the
        // StaticCall arm intact.
        $node = new MethodCall(new Variable('factory'), new Identifier('first'), [
            new Arg(new Array_([new ArrayItem($this->string('home')->value)])),
        ]);

        $this->assertSame('home', ReceiverViewNameResolver::resolve($node));
    }

    #[Test]
    public function refuses_method_call_dynamic_method_name(): void
    {
        // $factory->{$method}('home') — non-Identifier method name.
        $node = new MethodCall(new Variable('factory'), new Variable('method'), [$this->string('home')]);

        $this->assertNull(ReceiverViewNameResolver::resolve($node));
    }

    #[Test]
    public function refuses_first_with_empty_array(): void
    {
        // \View::first([]) — no literal candidates means no view name to
        // resolve. firstLiteralFromArrayArg never sets $resolved.
        $node = new StaticCall(new Name('View'), new Identifier('first'), [
            new Arg(new Array_([])),
        ]);

        $this->assertNull(ReceiverViewNameResolver::resolve($node));
    }

    #[Test]
    public function refuses_first_with_unpacked_argument(): void
    {
        // \View::first(...$views) — argument-level spread bypasses the
        // array-literal contract. firstLiteralFromArrayArg rejects via the
        // $arg->unpack guard.
        $arg = new Arg(new Array_([new ArrayItem($this->string('home')->value)]), unpack: true);
        $node = new StaticCall(new Name('View'), new Identifier('first'), [$arg]);

        $this->assertNull(ReceiverViewNameResolver::resolve($node));
    }

    #[Test]
    public function resolves_first_with_keyed_array_literal(): void
    {
        // \View::first(['primary' => 'home']) — Laravel accepts arbitrary
        // array keys in the candidate list; firstLiteralFromArrayArg checks
        // $item->value (the string), not $item->key. The resolver should
        // treat keyed and unkeyed single-literal arrays identically.
        $node = new StaticCall(new Name('View'), new Identifier('first'), [
            new Arg(new Array_([
                new ArrayItem($this->string('home')->value, new String_('primary')),
            ])),
        ]);

        $this->assertSame('home', ReceiverViewNameResolver::resolve($node));
    }

    #[Test]
    public function resolves_nullsafe_method_call_with_chain(): void
    {
        // view('home')?->with('a', 1) — confirms the nullsafe branch routes
        // through the chain-preserving allowlist, not only through the
        // `make`/`first` view-name arms.
        $inner = $this->funcCall('view', [$this->string('home')]);
        $chain = new NullsafeMethodCall($inner, new Identifier('with'), [$this->string('a'), $this->int(1)]);

        $this->assertSame('home', ReceiverViewNameResolver::resolve($chain));
    }

    #[Test]
    public function resolves_mailable_view_to_with_chain(): void
    {
        // (new InvoiceMail)->view('mail.invoice')->with('bio', $tainted) —
        // the Mailable `with` registration passes ['view','markdown','text']
        // as extra view binders; the resolver records 'mail.invoice' from
        // the view() call's first arg.
        $mailableConstruct = $this->mailableReceiverBare();
        $viewCall = new MethodCall($mailableConstruct, new Identifier('view'), [$this->string('mail.invoice')]);

        $this->assertSame(
            'mail.invoice',
            ReceiverViewNameResolver::resolve($viewCall, ['view', 'markdown', 'text']),
        );
    }

    #[Test]
    public function resolves_mailable_markdown_to_with_chain(): void
    {
        $mailableConstruct = $this->mailableReceiverBare();
        $markdownCall = new MethodCall(
            $mailableConstruct,
            new Identifier('markdown'),
            [$this->string('mail.invoice-markdown')],
        );

        $this->assertSame(
            'mail.invoice-markdown',
            ReceiverViewNameResolver::resolve($markdownCall, ['view', 'markdown', 'text']),
        );
    }

    #[Test]
    public function resolves_mailable_text_to_with_chain(): void
    {
        $mailableConstruct = $this->mailableReceiverBare();
        $textCall = new MethodCall(
            $mailableConstruct,
            new Identifier('text'),
            [$this->string('mail.invoice-text')],
        );

        $this->assertSame(
            'mail.invoice-text',
            ReceiverViewNameResolver::resolve($textCall, ['view', 'markdown', 'text']),
        );
    }

    #[Test]
    public function refuses_mailable_view_chain_without_extra_view_binders(): void
    {
        // Default mode (no extra view binders): `view('mail.invoice')` is a
        // MethodCall named 'view' on a non-Laravel receiver — the resolver
        // does not recognise it as a view-binder and falls through to []. The
        // existing `View::with` registration MUST continue to behave this way
        // (the chain head `(new InvoiceMail)->view(...)` is NOT a Laravel
        // view-builder receiver, so any positive resolution here would be a
        // regression).
        $mailableConstruct = $this->mailableReceiverBare();
        $viewCall = new MethodCall($mailableConstruct, new Identifier('view'), [$this->string('mail.invoice')]);

        $this->assertNull(ReceiverViewNameResolver::resolve($viewCall));
    }

    #[Test]
    public function refuses_mailable_chain_with_double_view_binders(): void
    {
        // (new InvoiceMail)->view('a')->view('b') — Laravel keeps 'b' for
        // the $view slot, but the resolver refuses rather than pick a
        // single binder. Same soundness rule as multi-literal
        // `View::first(['a','b'])`.
        $mailableConstruct = $this->mailableReceiverBare();
        $viewA = new MethodCall($mailableConstruct, new Identifier('view'), [$this->string('a')]);
        $viewB = new MethodCall($viewA, new Identifier('view'), [$this->string('b')]);

        $this->assertNull(ReceiverViewNameResolver::resolve($viewB, ['view', 'markdown', 'text']));
    }

    #[Test]
    public function refuses_mailable_chain_with_view_and_text_binders(): void
    {
        // (new InvoiceMail)->view('a')->text('b') — Laravel binds 'a' to
        // the $view slot AND 'b' to the $textView slot; with() data flows
        // to both. The resolver cannot pick one safely, so refuse.
        $mailableConstruct = $this->mailableReceiverBare();
        $viewA = new MethodCall($mailableConstruct, new Identifier('view'), [$this->string('a')]);
        $textB = new MethodCall($viewA, new Identifier('text'), [$this->string('b')]);

        $this->assertNull(ReceiverViewNameResolver::resolve($textB, ['view', 'markdown', 'text']));
    }

    #[Test]
    public function refuses_mailable_chain_with_view_and_markdown_binders(): void
    {
        // (new InvoiceMail)->markdown('m')->view('v') — same multi-binder
        // refusal across the view/markdown pair.
        $mailableConstruct = $this->mailableReceiverBare();
        $markdownM = new MethodCall($mailableConstruct, new Identifier('markdown'), [$this->string('m')]);
        $viewV = new MethodCall($markdownM, new Identifier('view'), [$this->string('v')]);

        $this->assertNull(ReceiverViewNameResolver::resolve($viewV, ['view', 'markdown', 'text']));
    }

    #[Test]
    public function refuses_mailable_chain_with_literal_then_dynamic_binder(): void
    {
        // (new InvoiceMail)->view('a')->view($dynamic) — Laravel binds
        // `$this->view = $dynamic` at runtime (last call wins). A naive
        // resolver that counts only literal candidates would return 'a';
        // but 'a' is silently overridden at runtime and could be SAFE
        // while $dynamic resolves to an UNSAFE_KEYS template. Refuse.
        $mailableConstruct = $this->mailableReceiverBare();
        $viewA = new MethodCall($mailableConstruct, new Identifier('view'), [$this->string('a')]);
        $viewDynamic = new MethodCall($viewA, new Identifier('view'), [new Arg(new Variable('dynamic'))]);

        $this->assertNull(
            ReceiverViewNameResolver::resolve(
                $viewDynamic,
                ['view', 'markdown', 'text'],
                recurseThroughUnknownMethods: true,
            ),
        );
    }

    #[Test]
    public function refuses_mailable_chain_with_dynamic_then_literal_binder(): void
    {
        // Symmetric: (new InvoiceMail)->view($dynamic)->view('a'). The
        // dynamic binder is upstream; same soundness rule — the chain
        // has two binders, only one is statically recoverable, so we
        // cannot prove `'a'` is the runtime view.
        $mailableConstruct = $this->mailableReceiverBare();
        $viewDynamic = new MethodCall(
            $mailableConstruct,
            new Identifier('view'),
            [new Arg(new Variable('dynamic'))],
        );
        $viewA = new MethodCall($viewDynamic, new Identifier('view'), [$this->string('a')]);

        $this->assertNull(
            ReceiverViewNameResolver::resolve(
                $viewA,
                ['view', 'markdown', 'text'],
                recurseThroughUnknownMethods: true,
            ),
        );
    }

    #[Test]
    public function refuses_mailable_chain_with_literal_view_then_dynamic_text(): void
    {
        // Cross-binder variant: (new InvoiceMail)->view('a')->text($dynamic).
        // Mailable binds 'a' to $view AND $dynamic to $textView; both
        // slots receive the with() data. Refuse — same rule as the
        // multi-literal cross-binder case.
        $mailableConstruct = $this->mailableReceiverBare();
        $viewA = new MethodCall($mailableConstruct, new Identifier('view'), [$this->string('a')]);
        $textDynamic = new MethodCall(
            $viewA,
            new Identifier('text'),
            [new Arg(new Variable('dynamic'))],
        );

        $this->assertNull(
            ReceiverViewNameResolver::resolve(
                $textDynamic,
                ['view', 'markdown', 'text'],
                recurseThroughUnknownMethods: true,
            ),
        );
    }

    #[Test]
    public function resolves_mailable_view_with_intervening_with_call(): void
    {
        // (new InvoiceMail)->view('mail.invoice')->with('a', 1) — `with()`
        // is chain-preserving; the resolver still finds the single
        // view-binder upstream.
        $mailableConstruct = $this->mailableReceiverBare();
        $viewCall = new MethodCall($mailableConstruct, new Identifier('view'), [$this->string('mail.invoice')]);
        $withCall = new MethodCall($viewCall, new Identifier('with'), [$this->string('a'), $this->int(1)]);

        $this->assertSame(
            'mail.invoice',
            ReceiverViewNameResolver::resolve($withCall, ['view', 'markdown', 'text']),
        );
    }

    #[Test]
    public function refuses_mailable_chain_when_extra_view_binders_empty(): void
    {
        // Explicit regression guard: even a single-binder chain is invisible
        // when extraViewBinders is empty. The pre-PR-6 caller (`View::with`)
        // must not start resolving Mailable chains it never supported.
        $mailableConstruct = $this->mailableReceiverBare();
        $viewCall = new MethodCall($mailableConstruct, new Identifier('view'), [$this->string('mail.invoice')]);
        $withCall = new MethodCall($viewCall, new Identifier('with'), [$this->string('a'), $this->int(1)]);

        $this->assertNull(ReceiverViewNameResolver::resolve($withCall, []));
    }

    #[Test]
    public function refuses_mailable_chain_with_variable_view_argument(): void
    {
        // (new InvoiceMail)->view($dynamic)->with(...) — view-binder
        // argument is non-literal, so no candidate is recorded and the
        // chain resolves to null (zero candidates, not multi-binder).
        $mailableConstruct = $this->mailableReceiverBare();
        $viewCall = new MethodCall(
            $mailableConstruct,
            new Identifier('view'),
            [new Arg(new Variable('dynamic'))],
        );

        $this->assertNull(ReceiverViewNameResolver::resolve($viewCall, ['view', 'markdown', 'text']));
    }

    #[Test]
    public function resolves_mailable_chain_through_intervening_decorator(): void
    {
        // (new InvoiceMail)->view('emails.invoice')->subject('Hi')->with(...)
        // — `subject()` is not a view-binder; it returns $this. In Mailable
        // mode the resolver recurses through unknown methods so the
        // upstream `view()` binder is not silently lost. This is the
        // common production chain shape; without the recurse-unknowns
        // rule, real-world Mailable taint coverage would be near-zero.
        $mailableConstruct = $this->mailableReceiverBare();
        $viewCall = new MethodCall($mailableConstruct, new Identifier('view'), [$this->string('emails.invoice')]);
        $subjectCall = new MethodCall($viewCall, new Identifier('subject'), [$this->string('Hi')]);

        $this->assertSame(
            'emails.invoice',
            ReceiverViewNameResolver::resolve(
                $subjectCall,
                ['view', 'markdown', 'text'],
                recurseThroughUnknownMethods: true,
            ),
        );
    }

    #[Test]
    public function resolves_mailable_chain_through_multiple_decorators(): void
    {
        // (new InvoiceMail)->subject('Hi')->from('a@b')->view('emails.invoice')
        //                   ->locale('en')->with(...) — multiple non-binders
        // upstream and downstream of the view binder. Mailable mode recurses
        // through every unknown method without contributing a candidate.
        $mailableConstruct = $this->mailableReceiverBare();
        $subjectCall = new MethodCall($mailableConstruct, new Identifier('subject'), [$this->string('Hi')]);
        $fromCall = new MethodCall($subjectCall, new Identifier('from'), [$this->string('a@b')]);
        $viewCall = new MethodCall($fromCall, new Identifier('view'), [$this->string('emails.invoice')]);
        $localeCall = new MethodCall($viewCall, new Identifier('locale'), [$this->string('en')]);

        $this->assertSame(
            'emails.invoice',
            ReceiverViewNameResolver::resolve(
                $localeCall,
                ['view', 'markdown', 'text'],
                recurseThroughUnknownMethods: true,
            ),
        );
    }

    #[Test]
    public function refuses_view_chain_through_unknown_method_in_default_mode(): void
    {
        // Default `View::with` mode: an unknown method in the chain (e.g.
        // `$builder->customMethod()`) MUST stop the walk. PR-3's "view
        // not in map → no sink" precision policy applies to View::with;
        // recursing through unknown methods is a Mailable-mode-only
        // relaxation justified by Illuminate\Mail\Mailable's decorator-
        // heavy API shape.
        $viewCall = $this->funcCall('view', [$this->string('home')]);
        $unknownCall = new MethodCall($viewCall, new Identifier('customMethod'), []);

        $this->assertNull(ReceiverViewNameResolver::resolve($unknownCall));
    }

    #[Test]
    public function refuses_interleaved_view_binders_through_with(): void
    {
        // view('a')->with('x', 1)->view('b')->with('y', 2) — the
        // interleaved `with()` does not hide the upstream `view('a')`
        // binder. Two binders observed → refuse. Guards against a regression
        // where the chain-preserving `with` arm forgets to recurse before
        // recording the outer view().
        $viewA = $this->funcCall('view', [$this->string('a')]);
        $withX = new MethodCall($viewA, new Identifier('with'), [$this->string('x'), $this->int(1)]);
        $viewB = new MethodCall($withX, new Identifier('view'), [$this->string('b')]);

        $this->assertNull(ReceiverViewNameResolver::resolve($viewB, ['view', 'markdown', 'text']));
    }

    /**
     * @param list<Arg> $args
     */
    private function funcCall(string $name, array $args): FuncCall
    {
        return new FuncCall(new Name($name), $args);
    }

    /**
     * Synthetic `new InvoiceMail()` node used as the chain head of Mailable
     * receiver-walk tests. The class name is documentation-only — the
     * resolver is class-agnostic — so all callers share the bare
     * `InvoiceMail` form here. The matching helper
     * {@see \Tests\Psalm\LaravelPlugin\Unit\Handlers\Views\BladeAwareViewTaintHandlerTest::mailableReceiverFqn()}
     * uses the fully-qualified `App\Mail\InvoiceMail` form to better
     * mirror what Psalm reports for a real codebase; the naming
     * asymmetry is deliberate so a future "consolidate into shared
     * helper" refactor cannot silently flip either test's behaviour.
     */
    private function mailableReceiverBare(): \PhpParser\Node\Expr\New_
    {
        return new \PhpParser\Node\Expr\New_(new Name('InvoiceMail'));
    }

    private function string(string $value): Arg
    {
        return new Arg(new String_($value));
    }

    private function int(int $value): Arg
    {
        return new Arg(new \PhpParser\Node\Scalar\Int_($value));
    }
}
