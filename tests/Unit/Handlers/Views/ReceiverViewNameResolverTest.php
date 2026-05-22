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

    /**
     * @param list<Arg> $args
     */
    private function funcCall(string $name, array $args): FuncCall
    {
        return new FuncCall(new Name($name), $args);
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
