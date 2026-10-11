--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Reflector;

/** @return list<string> */
function parameter_classes(\ReflectionParameter $parameter): array
{
    /** @psalm-check-type-exact $classes = list<string> */
    $classes = Reflector::getParameterClassNames($parameter);

    return $classes;
}
?>
--EXPECTF--
