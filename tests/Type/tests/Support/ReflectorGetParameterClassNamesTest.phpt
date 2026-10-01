--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Reflector;

/** @return array<int, class-string> */
function parameter_classes(\ReflectionParameter $parameter): array
{
    /** @psalm-check-type-exact $classes = array<int, class-string> */
    $classes = Reflector::getParameterClassNames($parameter);

    return $classes;
}
?>
--EXPECTF--
