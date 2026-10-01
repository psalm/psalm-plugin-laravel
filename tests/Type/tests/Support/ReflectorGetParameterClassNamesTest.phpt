--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Reflector;

/** @return list<class-string> */
function parameter_classes(\ReflectionParameter $parameter): array
{
    /** @psalm-check-type-exact $classes = list<class-string> */
    $classes = Reflector::getParameterClassNames($parameter);

    return $classes;
}
?>
--EXPECTF--
