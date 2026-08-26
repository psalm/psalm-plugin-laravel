<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Http;

use PhpParser\Node;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\New_;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\CodeLocation;
use Psalm\LaravelPlugin\Handlers\Http\ResponseFactoryTaintHandler;

/**
 * The handler re-derives everything from the emitted issue, so its two proof steps are pure and
 * testable in isolation: which journey tails name the response factory's html sink, and which
 * `make()` call shapes prove a literal attachment disposition. Both fail toward a retained finding,
 * which is what most of these cases pin.
 */
#[CoversClass(ResponseFactoryTaintHandler::class)]
final class ResponseFactoryTaintHandlerTest extends TestCase
{
    #[Test]
    public function accepts_the_journey_tail_of_every_exempt_call_route(): void
    {
        $location = $this->codeLocation();

        foreach ([
            'call to Illuminate\Routing\ResponseFactory::make',
            'call to Illuminate\Contracts\Routing\ResponseFactory::make',
            'call to Illuminate\Support\Facades\Response::make',
            'call to Illuminate\Http\Response::__construct',
        ] as $label) {
            $this->assertSame($location, $this->contentLocationOfMakeSink([$this->node('call to something-else'), $this->node($label, $location)]), $label);
        }
    }

    /**
     * The bare sink id with no `call to ` prefix is the shape Psalm emits when the sink's file is
     * not reportable and the issue falls back to the SOURCE location: the tail then points into a
     * stub, where no call site exists to re-check.
     */
    #[Test]
    #[DataProvider('provideRetainedJourneyTails')]
    public function declines_tails_that_do_not_prove_the_sink(string $label): void
    {
        $this->assertNull($this->contentLocationOfMakeSink([$this->node($label, $this->codeLocation())]));
    }

    /** @return iterable<string, array{string}> */
    public static function provideRetainedJourneyTails(): iterable
    {
        yield 'root alias' => ['call to Response::make'];
        yield 'sink id fallback' => ['Illuminate\Contracts\Routing\ResponseFactory::make#1'];
        yield 'facade sink id fallback' => ['Illuminate\Support\Facades\Response::make#1'];
        yield 'custom receiver' => ['call to App\Http\ResponseFactory::make'];
        yield 'other factory method' => ['call to Illuminate\Routing\ResponseFactory::view'];
        yield 'echo sink' => ['echo'];
    }

    #[Test]
    public function declines_an_empty_journey_and_a_locationless_tail(): void
    {
        $this->assertNull($this->contentLocationOfMakeSink([]));
        $this->assertNull($this->contentLocationOfMakeSink([$this->node('call to Illuminate\Routing\ResponseFactory::make')]));
    }

    /** An exempt label anywhere but the tail is a hop through the sink, not the sink itself. */
    #[Test]
    public function declines_an_exempt_label_that_is_not_the_tail(): void
    {
        $journey = [
            $this->node('call to Illuminate\Routing\ResponseFactory::make', $this->codeLocation()),
            $this->node('echo', $this->codeLocation()),
        ];

        $this->assertNull($this->contentLocationOfMakeSink($journey));
    }

    #[Test]
    #[DataProvider('provideMakeCalls')]
    public function proves_attachment_only_for_literal_downloads(string $call, bool $proven): void
    {
        $this->assertSame($proven, $this->provesLiteralAttachment($call));
    }

    /** @return iterable<string, array{string, bool}> */
    public static function provideMakeCalls(): iterable
    {
        yield 'bare attachment' => ['$r->make($c, 200, ["Content-Disposition" => "attachment"])', true];
        yield 'attachment with filename' => ['$r->make($c, 200, ["Content-Disposition" => "attachment; filename=\"x.csv\""])', true];
        yield 'case insensitive header and value' => ['$r->make($c, 200, ["CONTENT-disposition" => " \tATTACHMENT ; filename=x.csv\t "])', true];
        yield 'alongside a content type' => ['$r->make($c, 200, ["Content-Type" => "text/csv", "Content-Disposition" => "attachment"])', true];
        yield 'static call' => ['Response::make($c, 200, ["Content-Disposition" => "attachment"])', true];

        yield 'no headers argument' => ['$r->make($c)', false];
        yield 'content type only' => ['$r->make($c, 200, ["Content-Type" => "text/csv"])', false];
        yield 'fourth argument' => ['$r->make($c, 200, ["Content-Disposition" => "attachment"], true)', false];
        // PHP allows a named argument only after every positional one, so these three are the only
        // reachable named shapes. The last is the load-bearing one: the earlier two are also caught
        // by the same check on the third argument.
        yield 'all named arguments' => ['$r->make(content: $c, status: 200, headers: ["Content-Disposition" => "attachment"])', false];
        yield 'named from the status argument' => ['$r->make($c, status: 200, headers: ["Content-Disposition" => "attachment"])', false];
        yield 'named headers argument only' => ['$r->make($c, 200, headers: ["Content-Disposition" => "attachment"])', false];
        yield 'spread headers' => ['$r->make($c, 200, ...[["Content-Disposition" => "attachment"]])', false];
        yield 'variable headers' => ['$r->make($c, 200, $headers)', false];
        yield 'spread entry' => ['$r->make($c, 200, ["Content-Disposition" => "attachment", ...$extra])', false];
        yield 'computed key' => ['$r->make($c, 200, ["Content-" . "Disposition" => "attachment"])', false];
        yield 'variable value' => ['$r->make($c, 200, ["Content-Disposition" => $disposition])', false];
        yield 'list value' => ['$r->make($c, 200, ["Content-Disposition" => ["attachment"]])', false];
        yield 'list entry' => ['$r->make($c, 200, ["attachment"])', false];
        yield 'duplicate disposition' => ['$r->make($c, 200, ["Content-Disposition" => "attachment", "content-disposition" => "attachment"])', false];
        yield 'attachment with an extended filename' => ['$r->make($c, 200, ["Content-Disposition" => "attachment; filename*=UTF-8\'\'x.csv"])', true];
        yield 'attachment with a quoted semicolon' => ['$r->make($c, 200, ["Content-Disposition" => "attachment; filename=\"a;b\""])', true];
        yield 'attachment with an unknown parameter' => ['$r->make($c, 200, ["Content-Disposition" => "attachment; junk"])', true];

        // Symfony folds `_` onto `-` before keying, so these are one header at runtime and the last
        // write decides. Both orders keep the sink, and the underscore spelling never proves alone.
        yield 'underscore header' => ['$r->make($c, 200, ["Content_Disposition" => "attachment"])', false];
        yield 'underscore inline shadows a dashed attachment' => ['$r->make($c, 200, ["Content-Disposition" => "attachment", "Content_Disposition" => "inline"])', false];
        yield 'dashed attachment shadows an underscore inline' => ['$r->make($c, 200, ["Content_Disposition" => "inline", "Content-Disposition" => "attachment"])', false];
        yield 'underscore duplicate of a dashed attachment' => ['$r->make($c, 200, ["Content-Disposition" => "attachment", "CONTENT_DISPOSITION" => "attachment"])', false];
        yield 'inline disposition' => ['$r->make($c, 200, ["Content-Disposition" => "inline; filename=x.csv"])', false];
        yield 'attachment prefix' => ['$r->make($c, 200, ["Content-Disposition" => "attachmentx"])', false];
        yield 'missing semicolon' => ['$r->make($c, 200, ["Content-Disposition" => "attachment filename=x.csv"])', false];
        yield 'empty parameter' => ['$r->make($c, 200, ["Content-Disposition" => "attachment;"])', false];
        yield 'whitespace parameter' => ['$r->make($c, 200, ["Content-Disposition" => "attachment; \t"])', false];

        // Trimming first would approve values PHP refuses to emit, leaving the response HTML.
        yield 'smuggled header' => ['$r->make($c, 200, ["Content-Disposition" => "attachment;\nX-Injected: x"])', false];
        yield 'trailing newline' => ['$r->make($c, 200, ["Content-Disposition" => "attachment\n"])', false];
        yield 'leading carriage return' => ['$r->make($c, 200, ["Content-Disposition" => "\rattachment"])', false];
        yield 'embedded nul' => ['$r->make($c, 200, ["Content-Disposition" => "attachment\0"])', false];

        // Every other C0 control and DEL, none of which trim() removes on the way to the token.
        yield 'trailing vertical tab' => ['$r->make($c, 200, ["Content-Disposition" => "attachment\v"])', false];
        yield 'control in a parameter' => ['$r->make($c, 200, ["Content-Disposition" => "attachment; \x01"])', false];
        yield 'form feed' => ['$r->make($c, 200, ["Content-Disposition" => "attachment\f"])', false];
        yield 'trailing delete' => ['$r->make($c, 200, ["Content-Disposition" => "attachment\x7f"])', false];
        yield 'escape in a filename' => ['$r->make($c, 200, ["Content-Disposition" => "attachment; filename=\"a\x1bb\""])', false];

        // Horizontal tab stays accepted: it is the one control a field value carries legitimately.
        yield 'horizontal tab around the token' => ['$r->make($c, 200, ["Content-Disposition" => " \tattachment\t "])', true];
        yield 'token followed by a tab and text' => ['$r->make($c, 200, ["Content-Disposition" => "attachment\tfilename=x.csv"])', false];

        // #1416 widening 3: the `Illuminate\Http\Response` constructor shares the same sink and proof.
        yield 'new Response with literal attachment' => ['new \Illuminate\Http\Response($c, 200, ["Content-Disposition" => "attachment"])', true];
        yield 'new Response with named headers argument' => ['new \Illuminate\Http\Response($c, 200, headers: ["Content-Disposition" => "attachment"])', false];

        // #1416 widening 1: a local variable resolved to the single array literal assigned to it.
        yield 'variable resolved to a single literal assignment' => ['function f($r, $c) { $headers = ["Content-Disposition" => "attachment"]; $r->make($c, 200, $headers); }', true];
        yield 'variable with no enclosing function-like keeps the sink' => ['$headers = ["Content-Disposition" => "attachment"]; $r->make($c, 200, $headers)', false];
        yield 'variable reassigned before the call' => ['function f($r, $c) { $headers = ["Content-Disposition" => "inline"]; $headers = ["Content-Disposition" => "attachment"]; $r->make($c, 200, $headers); }', false];
        yield 'variable dim-mutated before the call' => ['function f($r, $c) { $headers = ["Content-Disposition" => "attachment"]; $headers["X-Extra"] = "y"; $r->make($c, 200, $headers); }', false];
        yield 'variable passed elsewhere before the call' => ['function f($r, $c, $other) { $headers = ["Content-Disposition" => "attachment"]; $other->log($headers); $r->make($c, 200, $headers); }', false];
        yield 'variable captured by a nested closure' => ['function f($r, $c) { $headers = ["Content-Disposition" => "attachment"]; $cb = function () use ($headers) { return $headers; }; $r->make($c, 200, $headers); }', false];
        yield 'variable assigned from a non-array expression' => ['function f($r, $c, $h) { $headers = $h; $r->make($c, 200, $headers); }', false];
        yield 'variable assigned by AssignOp' => ['function f($r, $c) { $headers = ["Content-Disposition" => "x"]; $headers ??= ["Content-Disposition" => "attachment"]; $r->make($c, 200, $headers); }', false];
        yield 'variable assigned after the call' => ['function f($r, $c) { $r->make($c, 200, $headers); $headers = ["Content-Disposition" => "attachment"]; }', false];
        yield 'variable resolution bails on a sibling variable-variable' => ['function f($r, $c, $name) { $headers = ["Content-Disposition" => "attachment"]; $$name = 1; $r->make($c, 200, $headers); }', false];
        yield 'variable resolution bails on extract() in scope' => ['function f($r, $c, $vars) { $headers = ["Content-Disposition" => "attachment"]; extract($vars); $r->make($c, 200, $headers); }', false];
        yield 'variable resolution bails on compact() in scope' => ['function f($r, $c) { $headers = ["Content-Disposition" => "attachment"]; $all = compact("headers"); $r->make($c, 200, $headers); }', false];
    }

    /** The content argument's span is the only link from the emitted issue back to the call site. */
    #[Test]
    public function finds_the_make_call_by_its_content_argument_span(): void
    {
        $code = '<?php $a->make($first, 200, []); $b->make($second, 200, []);';
        $stmts = $this->parse($code);
        $second = \strpos($code, '$second');
        $this->assertIsInt($second);

        $call = $this->findMakeCallWithContentAt($stmts, [$second, $second + \strlen('$second')]);

        $this->assertInstanceOf(MethodCall::class, $call);
        $this->assertSame('second', $call->getArgs()[0]->value->name ?? null);
    }

    #[Test]
    public function declines_when_no_make_call_owns_the_span(): void
    {
        $stmts = $this->parse('<?php $a->send($first, 200, []);');

        $this->assertNull($this->findMakeCallWithContentAt($stmts, [6, 12]));
    }

    /** @param list<array{location: ?CodeLocation, label: string, entry_path_type: string}> $journey */
    private function contentLocationOfMakeSink(array $journey): ?CodeLocation
    {
        /** @psalm-var ?CodeLocation */
        return (new \ReflectionMethod(ResponseFactoryTaintHandler::class, 'contentLocationOfMakeSink'))
            ->invoke(null, $journey);
    }

    /**
     * `$code` is appended with a trailing `;`, so it may be either a bare call expression (top-level
     * code, no enclosing function-like) or a full snippet containing a function definition followed
     * by the call: the extra empty statement after the closing brace is harmless.
     */
    private function provesLiteralAttachment(string $code): bool
    {
        $stmts = $this->parse('<?php ' . $code . ';');
        // Restricted to `make`/`New_` so a scoped snippet may freely contain another call (passing
        // the headers variable elsewhere, logging it, ...) before or after the one under test.
        $found = (new NodeFinder())->findFirst(
            $stmts,
            static fn(Node $node): bool => $node instanceof New_
                || (($node instanceof MethodCall || $node instanceof StaticCall)
                    && $node->name instanceof Node\Identifier
                    && \strtolower($node->name->name) === 'make'),
        );
        $this->assertTrue($found instanceof MethodCall || $found instanceof StaticCall || $found instanceof New_);

        /** @psalm-var bool */
        return (new \ReflectionMethod(ResponseFactoryTaintHandler::class, 'provesLiteralAttachment'))
            ->invoke(null, $stmts, $found);
    }

    /**
     * @param list<Node\Stmt> $stmts
     * @param array{0: int, 1: int} $bounds
     */
    private function findMakeCallWithContentAt(array $stmts, array $bounds): MethodCall|StaticCall|New_|null
    {
        /** @psalm-var MethodCall|StaticCall|New_|null */
        return (new \ReflectionMethod(ResponseFactoryTaintHandler::class, 'findMakeCallWithContentAt'))
            ->invoke(null, $stmts, $bounds);
    }

    /** @return list<Node\Stmt> */
    private function parse(string $code): array
    {
        $stmts = (new ParserFactory())->createForHostVersion()->parse($code);
        $this->assertIsArray($stmts);

        return \array_values($stmts);
    }

    /** @return array{location: ?CodeLocation, label: string, entry_path_type: string} */
    private function node(string $label, ?CodeLocation $location = null): array
    {
        return ['location' => $location, 'label' => $label, 'entry_path_type' => ''];
    }

    /** A journey entry is only ever read for its identity here, never for its file or bounds. */
    private function codeLocation(): CodeLocation
    {
        /** @psalm-var CodeLocation */
        return (new \ReflectionClass(CodeLocation::class))->newInstanceWithoutConstructor();
    }
}
