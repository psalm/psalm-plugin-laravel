<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Http;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Http\ResponseFactoryTaintHandler;
use Psalm\Plugin\EventHandler\Event\BeforeFileAnalysisEvent;

/**
 * `AddRemoveTaintsEvent` does not identify the enclosing method or parameter offset, so the
 * handler scopes removal through short-lived AST node ids. This test pins the file boundary that
 * prevents an id retained after an analysis failure from applying to a later file's AST.
 */
#[CoversClass(ResponseFactoryTaintHandler::class)]
final class ResponseFactoryTaintHandlerTest extends TestCase
{
    #[Test]
    public function clears_recorded_content_nodes_before_each_file(): void
    {
        $recordedContentIds = new \ReflectionProperty(ResponseFactoryTaintHandler::class, 'recordedContentIds');
        $recordedContentIds->setValue(null, [123 => null]);

        /** @var BeforeFileAnalysisEvent $event */
        $event = (new \ReflectionClass(BeforeFileAnalysisEvent::class))->newInstanceWithoutConstructor();
        ResponseFactoryTaintHandler::beforeAnalyzeFile($event);

        $this->assertSame([], $recordedContentIds->getValue());
    }

    protected function tearDown(): void
    {
        (new \ReflectionProperty(ResponseFactoryTaintHandler::class, 'recordedContentIds'))->setValue(null, []);
    }
}
