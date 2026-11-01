--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelVersion::skipBelow('13.9.0');
--FILE--
<?php declare(strict_types=1);

namespace App\Sandbox\ScopeSubclass;

use Illuminate\Database\Eloquent\SoftDeletingScope;

/**
 * Laravel's own `SoftDeletingScope` is generic from 13.9.0 (`@template TModel of Model`; earlier tags
 * declare the template on `apply()` only), and `TModel` has no default, so a subclass that omits
 * `@extends SoftDeletingScope<Model>` reports the missing argument. The stub's default on `Scope` covers
 * a direct `implements Scope` only, and the former `SuppressHandler` entry that hid this is gone.
 */
final class OwnSoftDeletingScope extends SoftDeletingScope
{
}
?>
--EXPECTF--
MissingTemplateParam on line %d: App\Sandbox\ScopeSubclass\OwnSoftDeletingScope has missing template params when extending Illuminate\Database\Eloquent\SoftDeletingScope, expecting 1
