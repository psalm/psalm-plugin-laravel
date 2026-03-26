--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function useSessionManager(): void {
    echo session()->getName();
}
?>
--EXPECTF--
