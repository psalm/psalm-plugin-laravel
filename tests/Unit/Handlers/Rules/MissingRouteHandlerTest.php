<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Rules;

use Illuminate\Routing\Redirector;
use Illuminate\Routing\UrlGenerator;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\ClassConstFetch;
use PhpParser\Node\Expr\FuncCall;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PhpParser\Node\Scalar\String_;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\LaravelPlugin\Handlers\Rules\MissingRouteHandler;
use Psalm\Plugin\EventHandler\Event\FunctionReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\StatementsSource;
use Psalm\Type\Union;

/**
 * Unit-level coverage for {@see MissingRouteHandler}'s pure gate logic (literal extraction,
 * method-name gate, enabled/disabled state). Every scenario here asserts the handler declines
 * (returns non-Union) — it never reaches IssueBuffer::accepts() in-process, since no Psalm
 * runtime is initialized in a plain PHPUnit test (same convention as MissingViewHandlerTest).
 * The actual positive emission is guarded end-to-end by
 * {@see \Tests\Psalm\LaravelPlugin\Unit\Handlers\MissingRouteEmissionTest}, a real Psalm
 * subprocess against a fixture with a populated route table.
 */
#[CoversClass(MissingRouteHandler::class)]
final class MissingRouteHandlerTest extends TestCase
{
    protected function setUp(): void
    {
        MissingRouteHandler::init(['dashboard' => true, 'posts.show' => true]);
    }

    protected function tearDown(): void
    {
        MissingRouteHandler::reset();
    }

    #[Test]
    public function returns_route_and_to_route_function_ids(): void
    {
        $this->assertSame(['route', 'to_route'], MissingRouteHandler::getFunctionIds());
    }

    #[Test]
    public function registers_the_service_classes_and_canonical_facades(): void
    {
        // FacadeMapProvider is not initialized in unit tests, so only the hardcoded
        // entries are present — the type test (MissingRouteTest.phpt) verifies the
        // FacadeMapProvider-discovered aliases are included when fully booted.
        $classNames = MissingRouteHandler::getClassLikeNames();

        $this->assertContains(UrlGenerator::class, $classNames);
        $this->assertContains(Redirector::class, $classNames);
        $this->assertContains(\Illuminate\Support\Facades\URL::class, $classNames);
        $this->assertContains(\Illuminate\Support\Facades\Redirect::class, $classNames);
    }

    #[Test]
    public function skips_no_arguments(): void
    {
        $event = $this->createFunctionEvent('route', []);

        $this->assertNotInstanceOf(Union::class, MissingRouteHandler::getFunctionReturnType($event));
    }

    #[Test]
    public function skips_dynamic_variable_argument(): void
    {
        $event = $this->createFunctionEvent('route', [new Arg(new Variable('routeName'))]);

        $this->assertNotInstanceOf(Union::class, MissingRouteHandler::getFunctionReturnType($event));
    }

    #[Test]
    public function skips_leading_spread_argument_even_when_it_wraps_a_literal_string(): void
    {
        // Deliberately a String_ node (not a Variable) inside the spread: extractLiteralStringArg()
        // alone would happily read 'anything-unregistered' out of this node, so this only stays
        // silent because the ->unpack guard short-circuits first. A Variable arg here would pass
        // even with that guard deleted, since extractLiteralStringArg() already returns null for
        // non-String_ nodes — this shape is required to give the guard real teeth.
        $event = $this->createFunctionEvent('route', [new Arg(new String_('anything-unregistered'), false, true)]);

        $this->assertNotInstanceOf(Union::class, MissingRouteHandler::getFunctionReturnType($event));
    }

    #[Test]
    public function skips_backed_enum_route_name(): void
    {
        $enumFetch = new ClassConstFetch(new Name('RouteEnum'), new Identifier('Dashboard'));
        $event = $this->createFunctionEvent('route', [new Arg($enumFetch)]);

        $this->assertNotInstanceOf(Union::class, MissingRouteHandler::getFunctionReturnType($event));
    }

    #[Test]
    public function skips_registered_route_name(): void
    {
        $event = $this->createFunctionEvent('route', [new Arg(new String_('dashboard'))]);

        // If the handler incorrectly tried to emit an issue for a registered name, it
        // would throw here (no Psalm runtime initialized in a plain unit test).
        $this->assertNotInstanceOf(Union::class, MissingRouteHandler::getFunctionReturnType($event));
    }

    #[Test]
    public function skips_when_not_enabled(): void
    {
        MissingRouteHandler::reset();

        $event = $this->createFunctionEvent('route', [new Arg(new String_('anything'))]);

        // An unregistered name would normally emit; with the handler disabled it must
        // decline instead of throwing (no Psalm runtime available here).
        $this->assertNotInstanceOf(Union::class, MissingRouteHandler::getFunctionReturnType($event));
    }

    #[Test]
    public function function_return_type_is_always_null(): void
    {
        // Diagnostic-only: even on the "unregistered" path, the return type stub/native
        // type is left alone, not narrowed or replaced.
        $event = $this->createFunctionEvent('route', [new Arg(new String_('dashboard'))]);

        $this->assertNull(MissingRouteHandler::getFunctionReturnType($event));
    }

    /**
     * Psalm always passes an already-lowercased method name to getMethodNameLowercase()
     * (the property is typed `lowercase-string`) — these are the exact strings the gate
     * must match, one per method the handler covers.
     *
     * @return iterable<string, array{string}>
     */
    public static function coveredMethodNameProvider(): iterable
    {
        yield 'route' => ['route'];
        yield 'signedroute' => ['signedroute'];
        yield 'temporarysignedroute' => ['temporarysignedroute'];
    }

    #[Test]
    #[DataProvider('coveredMethodNameProvider')]
    public function method_skips_registered_route_name_for_every_covered_method(string $methodName): void
    {
        $event = $this->createMethodEvent($methodName, 'dashboard');

        $this->assertNotInstanceOf(Union::class, MissingRouteHandler::getMethodReturnType($event));
    }

    #[Test]
    public function method_skips_uncovered_method_names(): void
    {
        // An UNREGISTERED name on purpose: a registered name would pass this test even
        // with the method-name gate deleted, since checkRouteExists() would still return
        // early on the isset() check — this shape is required to give the gate real teeth.
        $event = $this->createMethodEvent('previous', 'not-a-registered-route');

        $this->assertNotInstanceOf(Union::class, MissingRouteHandler::getMethodReturnType($event));
    }

    #[Test]
    public function method_skips_no_arguments(): void
    {
        $event = $this->createMethodEvent('route', null);

        $this->assertNotInstanceOf(Union::class, MissingRouteHandler::getMethodReturnType($event));
    }

    #[Test]
    public function method_skips_dynamic_variable_argument(): void
    {
        $source = $this->createStub(StatementsSource::class);
        $source->method('getFilePath')->willReturn('/app/Http/Controllers/TestController.php');
        $source->method('getFileName')->willReturn('TestController.php');
        $source->method('getSuppressedIssues')->willReturn([]);

        $methodCall = new MethodCall(new Variable('url'), 'route');
        $methodCall->setAttribute('startFilePos', 0);
        $methodCall->setAttribute('endFilePos', 10);
        $methodCall->args = [new Arg(new Variable('routeName'))];

        $event = new MethodReturnTypeProviderEvent(
            $source,
            UrlGenerator::class,
            'route',
            $methodCall,
            new Context(),
            new CodeLocation($source, $methodCall),
        );

        $this->assertNotInstanceOf(Union::class, MissingRouteHandler::getMethodReturnType($event));
    }

    /**
     * @param list<Arg> $args
     */
    private function createFunctionEvent(string $functionName, array $args): FunctionReturnTypeProviderEvent
    {
        $source = $this->createStub(StatementsSource::class);
        $source->method('getFilePath')->willReturn('/app/Http/Controllers/TestController.php');
        $source->method('getFileName')->willReturn('TestController.php');
        $source->method('getSuppressedIssues')->willReturn([]);

        $funcCall = new FuncCall(new Name($functionName));
        $funcCall->setAttribute('startFilePos', 0);
        $funcCall->setAttribute('endFilePos', 10);
        $funcCall->args = $args;

        return new FunctionReturnTypeProviderEvent(
            $source,
            $functionName,
            $funcCall,
            new Context(),
            new CodeLocation($source, $funcCall),
        );
    }

    private function createMethodEvent(string $methodName, ?string $routeName): MethodReturnTypeProviderEvent
    {
        $source = $this->createStub(StatementsSource::class);
        $source->method('getFilePath')->willReturn('/app/Http/Controllers/TestController.php');
        $source->method('getFileName')->willReturn('TestController.php');
        $source->method('getSuppressedIssues')->willReturn([]);

        $methodCall = new MethodCall(new Variable('url'), $methodName);
        $methodCall->setAttribute('startFilePos', 0);
        $methodCall->setAttribute('endFilePos', 10);
        $methodCall->args = $routeName === null ? [] : [new Arg(new String_($routeName))];

        return new MethodReturnTypeProviderEvent(
            $source,
            UrlGenerator::class,
            $methodName,
            $methodCall,
            new Context(),
            new CodeLocation($source, $methodCall),
        );
    }
}
