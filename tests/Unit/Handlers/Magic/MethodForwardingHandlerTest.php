<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Magic;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Magic\MethodForwardingHandler;

#[CoversClass(MethodForwardingHandler::class)]
final class MethodForwardingHandlerTest extends TestCase
{
    /**
     * MethodForwardingHandler must NOT use ProxyMethodReturnTypeProvider::executeFakeCall().
     *
     * executeFakeCall() clones Psalm's node_data for every call — on large codebases with
     * thousands of relation method calls, this causes 50+ GB memory explosion.
     * The handler should resolve return types via ClassLikeStorage->declaring_method_ids instead.
     */
    #[Test]
    public function it_does_not_import_ProxyMethodReturnTypeProvider(): void
    {
        $reflection = new \ReflectionClass(MethodForwardingHandler::class);
        $fileName = $reflection->getFileName();
        $this->assertIsString($fileName);

        $fileContents = \file_get_contents($fileName);
        $this->assertIsString($fileContents);

        // Strip comments to avoid false positives from explanatory comments
        $tokens = \token_get_all($fileContents);
        $codeOnly = '';

        foreach ($tokens as $token) {
            if (\is_array($token) && \in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }

            $codeOnly .= \is_array($token) ? $token[1] : $token;
        }

        $this->assertStringNotContainsString(
            'ProxyMethodReturnTypeProvider',
            $codeOnly,
            'MethodForwardingHandler must not use ProxyMethodReturnTypeProvider::executeFakeCall() — '
            . 'it causes 50+ GB memory explosion on large projects. '
            . 'Use ClassLikeStorage->declaring_method_ids for method lookup instead.',
        );
    }
}
