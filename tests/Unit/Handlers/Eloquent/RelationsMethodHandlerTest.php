<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Tests\Unit\Handlers\Eloquent;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Eloquent\RelationsMethodHandler;

#[CoversClass(RelationsMethodHandler::class)]
final class RelationsMethodHandlerTest extends TestCase
{
    /**
     * RelationsMethodHandler must NOT use ProxyMethodReturnTypeProvider::executeFakeCall().
     *
     * executeFakeCall() clones Psalm's node_data for every call — on large codebases with
     * thousands of relation method calls, this causes 50+ GB memory explosion.
     * The handler should resolve return types via Codebase::methods->getStorage() instead.
     *
     * @see docs/perf-model-analysis.md "Memory Explosion" section
     */
    #[Test]
    public function it_does_not_use_expensive_fake_call_proxy(): void
    {
        $reflection = new \ReflectionClass(RelationsMethodHandler::class);
        $fileName = $reflection->getFileName();
        self::assertIsString($fileName);

        // Strip comments to avoid false positives from explanatory comments
        $tokens = \token_get_all((string) \file_get_contents($fileName));
        $codeOnly = '';
        foreach ($tokens as $token) {
            if (\is_array($token) && \in_array($token[0], [\T_COMMENT, \T_DOC_COMMENT], true)) {
                continue;
            }
            $codeOnly .= \is_array($token) ? $token[1] : $token;
        }

        self::assertStringNotContainsString(
            'ProxyMethodReturnTypeProvider',
            $codeOnly,
            'RelationsMethodHandler must not use ProxyMethodReturnTypeProvider::executeFakeCall() — '
            . 'it causes 50+ GB memory explosion on large projects. '
            . 'Use Codebase::methods->getStorage() for return type lookup instead. '
            . 'See docs/perf-model-analysis.md "Memory Explosion" section.',
        );
    }
}
