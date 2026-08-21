<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Http;

use PhpParser\Node\Expr\Variable;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Http\ResponseFactoryTaintHandler;
use Psalm\Plugin\EventHandler\Event\BeforeFileAnalysisEvent;

/**
 * `AddRemoveTaintsEvent` does not identify the enclosing method or parameter offset, so the handler
 * scopes removal through the identity of the recorded content node. These tests pin the two
 * properties that keeps sound: an entry never outlives the node it was recorded for, and the file
 * hook still drops whatever a mid-file analysis throw left behind.
 */
#[CoversClass(ResponseFactoryTaintHandler::class)]
final class ResponseFactoryTaintHandlerTest extends TestCase
{
    /**
     * A recorded entry must vanish with its node. Psalm frees foreign ASTs mid-file
     * (`ProjectAnalyzer::getMethodMutations()`, `ClassLikes::getTraitNode()`) without dispatching
     * `BeforeFileAnalysisEvent`, and PHP reissues freed object handles, so an entry keyed on
     * `spl_object_id` could be hit by an unrelated later node — stripping html taint off it.
     */
    #[Test]
    public function recorded_content_does_not_outlive_its_node(): void
    {
        $recorded = $this->recordedContent();

        $node = new Variable('content');
        $recorded->offsetSet($node, ['receiver' => null]);

        $this->assertCount(1, $recorded);

        unset($node);
        \gc_collect_cycles();

        $this->assertCount(0, $recorded, 'A recorded content node must not be retained after the AST is freed.');
    }

    #[Test]
    public function clears_recorded_content_nodes_before_each_file(): void
    {
        $recorded = $this->recordedContent();
        $node = new Variable('content');
        $recorded->offsetSet($node, ['receiver' => null]);

        /** @var BeforeFileAnalysisEvent $event */
        $event = (new \ReflectionClass(BeforeFileAnalysisEvent::class))->newInstanceWithoutConstructor();
        ResponseFactoryTaintHandler::beforeAnalyzeFile($event);

        $this->assertFalse($this->recordedContent()->offsetExists($node));
    }

    /** @return \WeakMap<object, mixed> */
    private function recordedContent(): \WeakMap
    {
        $property = new \ReflectionProperty(ResponseFactoryTaintHandler::class, 'recordedContent');
        $map = $property->getValue();

        if (!$map instanceof \WeakMap) {
            $map = new \WeakMap();
            $property->setValue(null, $map);
        }

        return $map;
    }

    #[\Override]
    protected function tearDown(): void
    {
        (new \ReflectionProperty(ResponseFactoryTaintHandler::class, 'recordedContent'))->setValue(null, null);
    }
}
