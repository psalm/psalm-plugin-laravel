--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

/** @psalm-taint-source input */
function userInput(): string
{
    return '';
}

final class ImportController
{
    public function __invoke(): void
    {
        echo userInput();
    }
}
?>
--EXPECTF--
TaintedHtml on line %d: Detected tainted HTML
TaintedTextWithQuotes on line %d: Detected tainted text with possible quotes
