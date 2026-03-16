<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Tests\Unit\Handlers\Auth;

use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Auth\AuthHandler;
use Psalm\Plugin\EventHandler\Event\MethodParamsProviderEvent;

#[CoversClass(AuthHandler::class)]
final class AuthHandlerTest extends TestCase
{
    /**
     * Methods with no parameters should return an empty array.
     *
     * @return list<array{string}>
     */
    public static function noParamMethodsProvider(): array
    {
        return [
            ['user'],
            ['getuser'],
            ['authenticate'],
            ['getlastattempted'],
        ];
    }

    #[DataProvider('noParamMethodsProvider')]
    public function testGetMethodParamsForNoParamMethods(string $method): void
    {
        $event = new MethodParamsProviderEvent(
            \Illuminate\Support\Facades\Auth::class,
            $method,
        );

        $params = AuthHandler::getMethodParams($event);

        $this->assertNotNull($params, "getMethodParams() must not return null for '{$method}' — Psalm 7 crashes on null for @method-annotated facade methods");
        $this->assertEmpty($params);
    }

    public function testGetMethodParamsForLoginUsingId(): void
    {
        $event = new MethodParamsProviderEvent(
            \Illuminate\Support\Facades\Auth::class,
            'loginusingid',
        );

        $params = AuthHandler::getMethodParams($event);

        $this->assertNotNull($params);
        $this->assertCount(2, $params);
        $this->assertSame('id', $params[0]->name);
        $this->assertSame('remember', $params[1]->name);
        $this->assertTrue($params[1]->is_optional);
    }

    public function testGetMethodParamsForOnceUsingId(): void
    {
        $event = new MethodParamsProviderEvent(
            \Illuminate\Support\Facades\Auth::class,
            'onceusingid',
        );

        $params = AuthHandler::getMethodParams($event);

        $this->assertNotNull($params);
        $this->assertCount(1, $params);
        $this->assertSame('id', $params[0]->name);
    }

    public function testGetMethodParamsForLogoutOtherDevices(): void
    {
        $event = new MethodParamsProviderEvent(
            \Illuminate\Support\Facades\Auth::class,
            'logoutotherdevices',
        );

        $params = AuthHandler::getMethodParams($event);

        $this->assertNotNull($params);
        $this->assertCount(1, $params);
        $this->assertSame('password', $params[0]->name);
    }

    public function testGetMethodParamsForUnknownMethod(): void
    {
        $event = new MethodParamsProviderEvent(
            \Illuminate\Support\Facades\Auth::class,
            'unknown',
        );

        $params = AuthHandler::getMethodParams($event);

        $this->assertNull($params);
    }
}
