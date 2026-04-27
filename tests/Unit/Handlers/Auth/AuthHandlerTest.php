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
     * Issue #854: when AuthManager / Factory are registered as a return type provider, Psalm
     * routes calls to methods reached only via __call (authenticate, getUser, getLastAttempted,
     * logoutOtherDevices — none of which appear on the @mixin Guard / StatefulGuard interfaces)
     * through MissingMethodCallHandler. Returning null from getMethodParams there triggers the
     * "Cannot get method params for ..." UnexpectedValueException. We must return non-null params
     * for every method we narrow on these receivers, exactly as we do for the facade.
     *
     * @return \Iterator<string, array{class-string, string}>
     */
    public static function nonFacadeReceiversProvider(): \Iterator
    {
        $methods = ['user', 'getuser', 'authenticate', 'getlastattempted', 'logoutotherdevices', 'loginusingid', 'onceusingid'];

        foreach ($methods as $method) {
            yield "AuthManager::{$method}" => [\Illuminate\Auth\AuthManager::class, $method];
            yield "Factory::{$method}" => [\Illuminate\Contracts\Auth\Factory::class, $method];
        }
    }

    /**
     * @param class-string $fqClassLikeName
     */
    #[DataProvider('nonFacadeReceiversProvider')]
    public function testGetMethodParamsReturnsNonNullForNonFacadeReceivers(string $fqClassLikeName, string $method): void
    {
        $event = new MethodParamsProviderEvent($fqClassLikeName, $method);

        $this->assertNotNull(
            AuthHandler::getMethodParams($event),
            "getMethodParams() must not return null for {$fqClassLikeName}::{$method} — Psalm 7 crashes when the method is reached via __call (issue #854)",
        );
    }

    /**
     * `guard()` is the one method we still defer to Laravel's source for non-facade receivers.
     * AuthManager and Factory both declare it as a real method, so Psalm can derive params from
     * the source — and skipping our override insulates us against future Laravel signature
     * changes (e.g. adding \UnitEnum to the parameter type) that would otherwise turn our
     * `string|null` override into false positives.
     *
     * @return \Iterator<string, array{class-string}>
     */
    public static function nonFacadeReceiversForGuardProvider(): \Iterator
    {
        yield 'AuthManager::guard' => [\Illuminate\Auth\AuthManager::class];
        yield 'Factory::guard' => [\Illuminate\Contracts\Auth\Factory::class];
    }

    /**
     * @param class-string $fqClassLikeName
     */
    #[DataProvider('nonFacadeReceiversForGuardProvider')]
    public function testGetMethodParamsReturnsNullForGuardOnNonFacadeReceivers(string $fqClassLikeName): void
    {
        $event = new MethodParamsProviderEvent($fqClassLikeName, 'guard');

        $this->assertNull(
            AuthHandler::getMethodParams($event),
            "getMethodParams() must return null for {$fqClassLikeName}::guard so Psalm uses the native signature",
        );
    }
}
