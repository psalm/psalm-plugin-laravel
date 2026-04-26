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
     * @return \Iterator<int<0, max>, array{string}>
     */
    public static function noParamMethodsProvider(): \Iterator
    {
        yield ['user'];
        yield ['getuser'];
        yield ['authenticate'];
        yield ['getlastattempted'];
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
        $this->assertFalse($params[0]->is_optional);
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
        $this->assertFalse($params[0]->is_optional);
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
        $this->assertFalse($params[0]->is_optional);
    }

    public function testGetMethodParamsForGuard(): void
    {
        $event = new MethodParamsProviderEvent(
            \Illuminate\Support\Facades\Auth::class,
            'guard',
        );

        $params = AuthHandler::getMethodParams($event);

        $this->assertNotNull($params, "getMethodParams() must not return null for 'guard' — Psalm 7 crashes on null for @method-annotated facade methods");
        $this->assertCount(1, $params);
        $this->assertSame('name', $params[0]->name);
        $this->assertTrue($params[0]->is_optional);
    }

    public function testGuardNameParameterTypeTracksLaravelVersion(): void
    {
        $method = new \ReflectionMethod(AuthHandler::class, 'getGuardNameParameterType');

        $this->assertStringNotContainsString(
            'UnitEnum',
            (string) $method->invoke(null, '12.99.0')->getId(),
        );
        $this->assertStringContainsString(
            'UnitEnum',
            (string) $method->invoke(null, '13.0.0')->getId(),
        );
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

    /**
     * For AuthManager / Factory the methods are declared in source or stubs, so Psalm can
     * resolve their parameter types without facade-specific overrides.
     * Returning our facade-oriented overrides there would narrow `guard()`'s
     * \UnitEnum|string|null parameter down to string|null and flag valid enum calls
     * as InvalidArgument. See {@see AuthHandler::getMethodParams}.
     *
     * @return \Iterator<string, array{class-string, string}>
     */
    public static function nonFacadeReceiversProvider(): \Iterator
    {
        $methods = ['user', 'getuser', 'authenticate', 'getlastattempted', 'guard', 'logoutotherdevices', 'loginusingid', 'onceusingid'];

        foreach ($methods as $method) {
            yield "AuthManager::{$method}" => [\Illuminate\Auth\AuthManager::class, $method];
            yield "Factory::{$method}" => [\Illuminate\Contracts\Auth\Factory::class, $method];
        }
    }

    /**
     * @param class-string $fqClassLikeName
     */
    #[DataProvider('nonFacadeReceiversProvider')]
    public function testGetMethodParamsReturnsNullForNonFacadeReceivers(string $fqClassLikeName, string $method): void
    {
        $event = new MethodParamsProviderEvent($fqClassLikeName, $method);

        $this->assertNull(
            AuthHandler::getMethodParams($event),
            "getMethodParams() must return null for {$fqClassLikeName}::{$method} so Psalm uses the native signature",
        );
    }
}
