<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Tests\Unit\Handlers\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Auth\AuthHandler;
use Psalm\Plugin\EventHandler\Event\MethodParamsProviderEvent;

#[CoversClass(AuthHandler::class)]
final class AuthHandlerTest extends TestCase
{
    public function testGetMethodParamsForUser(): void
    {
        $event = new MethodParamsProviderEvent(
            \Illuminate\Support\Facades\Auth::class,
            'user'
        );

        $params = AuthHandler::getMethodParams($event);

        $this->assertNotNull($params);
        $this->assertEmpty($params);
    }

    public function testGetMethodParamsForUnknownMethod(): void
    {
        $event = new MethodParamsProviderEvent(
            \Illuminate\Support\Facades\Auth::class,
            'unknown'
        );

        $params = AuthHandler::getMethodParams($event);

        $this->assertNull($params);
    }
}
