<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Rules;

use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\Codebase;
use Psalm\Context;
use Psalm\LaravelPlugin\Handlers\Rules\ModelMakeHandler;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\StatementsSource;

#[CoversClass(ModelMakeHandler::class)]
final class ModelMakeHandlerTest extends TestCase
{
    #[Test]
    public function ignores_non_static_call_expressions(): void
    {
        $variable = new Variable('user');
        $variable->setAttribute('startFilePos', 0);
        $variable->setAttribute('endFilePos', 4);

        $event = $this->createEvent($variable);

        $this->assertNull(ModelMakeHandler::afterExpressionAnalysis($event));
    }

    #[Test]
    public function ignores_non_make_static_calls(): void
    {
        $staticCall = new StaticCall(
            new Name('App\\Models\\User'),
            new Identifier('create'),
        );
        $staticCall->setAttribute('startFilePos', 0);
        $staticCall->setAttribute('endFilePos', 10);

        $event = $this->createEvent($staticCall);

        $this->assertNull(ModelMakeHandler::afterExpressionAnalysis($event));
    }

    #[Test]
    public function ignores_dynamic_method_names(): void
    {
        $staticCall = new StaticCall(
            new Name('App\\Models\\User'),
            new Variable('method'),
        );
        $staticCall->setAttribute('startFilePos', 0);
        $staticCall->setAttribute('endFilePos', 10);

        $event = $this->createEvent($staticCall);

        $this->assertNull(ModelMakeHandler::afterExpressionAnalysis($event));
    }

    #[Test]
    public function ignores_dynamic_class_references(): void
    {
        $staticCall = new StaticCall(
            new Variable('class'),
            new Identifier('make'),
        );
        $staticCall->setAttribute('startFilePos', 0);
        $staticCall->setAttribute('endFilePos', 10);

        $event = $this->createEvent($staticCall);

        $this->assertNull(ModelMakeHandler::afterExpressionAnalysis($event));
    }

    #[Test]
    public function ignores_make_without_resolved_name(): void
    {
        $name = new Name('User');
        // Intentionally not setting 'resolvedName' attribute

        $staticCall = new StaticCall($name, new Identifier('make'));
        $staticCall->setAttribute('startFilePos', 0);
        $staticCall->setAttribute('endFilePos', 10);

        $event = $this->createEvent($staticCall);

        $this->assertNull(ModelMakeHandler::afterExpressionAnalysis($event));
    }

    /**
     * @param \PhpParser\Node\Expr $expr
     */
    private function createEvent(\PhpParser\Node\Expr $expr): AfterExpressionAnalysisEvent
    {
        $source = $this->createStub(StatementsSource::class);
        $source->method('getFilePath')->willReturn('/project/app/Models/User.php');
        $source->method('getFileName')->willReturn('User.php');
        $source->method('getSuppressedIssues')->willReturn([]);

        $codebase = (new \ReflectionClass(Codebase::class))->newInstanceWithoutConstructor();

        return new AfterExpressionAnalysisEvent(
            $expr,
            new Context(),
            $source,
            $codebase,
        );
    }
}
