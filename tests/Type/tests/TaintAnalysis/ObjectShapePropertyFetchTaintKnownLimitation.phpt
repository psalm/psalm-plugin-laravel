--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox\ObjectShapeFetchTaint;

use App\Models\Mechanic;

/**
 * KNOWN LIMITATION (Psalm, not the plugin): Psalm 7 resolves a property fetch on an `object{p: T}` shape
 * without a data-flow edge, so taint written into the object does not reach `$obj->p`. A plain class and a
 * Model intersected with a shape (both cast from an stdClass) behave the same. This pins only that shape-only
 * limitation. It says nothing about the taint of a Model `@property` pseudo-property: the handler leaves
 * Psalm's read of those intact, and TaintedModelPseudoPropertyThroughObjectShape covers it.
 * The `$_GET` echo is the control that proves the taint run is active.
 */
class Plain
{
}

function plain_fetch(): void
{
    $o = (object) ['p' => $_GET['p'] ?? ''];
    /** @var Plain&object{p: string} $o */
    echo $o->p;
}

function model_fetch(): void
{
    $o = (object) ['p' => $_GET['p'] ?? ''];
    /** @var Mechanic&object{p: string} $o */
    echo $o->p;
}

function control(): void
{
    /** @var string $x */
    $x = $_GET['x'] ?? '';
    echo $x;
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
