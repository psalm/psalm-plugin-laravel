--FILE--
<?php declare(strict_types=1);

function two_args_to_set_cookie(): \Symfony\Component\HttpFoundation\Cookie
{
    return cookie('some.key', 42);
}

function single_arg_to_get_cookie(): \Symfony\Component\HttpFoundation\Cookie
{
    return cookie('some.key');
}

function no_args(): \Illuminate\Cookie\CookieJar
{
  return cookie();
}
?>
--EXPECTF--
