--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\PsalmVersion::skipOnRange('7.0.0-rc16', '7.0.0-rc26');
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

use Illuminate\Support\Collection;

final class ProxyTaintItem
{
    public function __construct(public string $secret) {}
}

/** @psalm-taint-source input */
function proxyTaintUserInput(): string
{
    return '';
}

function proxyTaintFill(): ProxyTaintItem
{
    return new ProxyTaintItem(proxyTaintUserInput());
}

/** @param Collection<int, ProxyTaintItem> $items */
function proxyTaintRender(Collection $items): void
{
    // The retyped `$items->map->secret` keeps the item property's taint edges.
    foreach ($items->map->secret as $secret) {
        echo $secret;
    }
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
