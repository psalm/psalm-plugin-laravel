--FILE--
<?php declare(strict_types=1);

use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Cookie;

/**
 * ResponseTrait::cookie() and withCookie() accept a Cookie object or variadic string
 * arguments forwarded to the cookie() helper via func_get_args().
 */
final class ResponseCookieTest
{
    public function cookieWithObject(Response $response): Response
    {
        return $response->cookie(new Cookie('name', 'value'));
    }

    public function cookieWithVariadicArgs(Response $response): Response
    {
        return $response->cookie('name', 'value', 60);
    }

    public function withCookieWithObject(Response $response): Response
    {
        return $response->withCookie(new Cookie('name', 'value'));
    }

    public function withCookieWithVariadicArgs(Response $response): Response
    {
        return $response->withCookie('name', 'value', 60);
    }
}
?>
--EXPECTF--
