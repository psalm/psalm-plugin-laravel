--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Sandbox\TaintedModelPseudoPropertyThroughObjectShape;

use App\Models\Customer;

/**
 * Taint written into a Model `@property` pseudo-property flows through Psalm's own pseudo-property read, shaped
 * (`Customer&object{...}`) or not. ModelObjectShapePropertyFetchHandler only retypes the fetch result, so the
 * four sinks below must report the same findings (vimeo/psalm#12110 workaround, #1944).
 */
function source(Customer $m): void
{
    /** @var string $id */
    $id = $_GET['id'] ?? '';
    $m->id = $id;

    /** @var non-empty-string $name */
    $name = $_GET['name'] ?? 'x';
    $m->first_name_using_legacy_accessor = $name;
}

function plain_property_sink(Customer $m): void
{
    echo $m->id;
}

/** @param Customer&object{id: string} $m */
function shaped_property_sink(Customer $m): void
{
    echo $m->id;
}

function plain_legacy_accessor_sink(Customer $m): void
{
    echo $m->first_name_using_legacy_accessor;
}

/** @param Customer&object{first_name_using_legacy_accessor: non-empty-string} $m */
function shaped_legacy_accessor_sink(Customer $m): void
{
    echo $m->first_name_using_legacy_accessor;
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
