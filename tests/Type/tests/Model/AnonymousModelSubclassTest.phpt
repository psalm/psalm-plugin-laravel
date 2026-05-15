--FILE--
<?php declare(strict_types=1);

use Illuminate\Database\Eloquent\Model;

// Regression: analysing `new class extends Model {}` must not trigger the plugin's
// "class could not be loaded by autoloader" warning, nor any type errors.
// Psalm assigns these a synthetic FQCN that is never autoloadable; the plugin
// now detects and skips them in ModelRegistrationHandler.
function test_anonymous_model_subclass(): Model
{
    return new class extends Model {};
}
?>
--EXPECTF--
