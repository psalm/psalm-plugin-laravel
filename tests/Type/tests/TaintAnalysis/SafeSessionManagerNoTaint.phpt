--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

function useSessionManager(): void {
    echo session()->getName();
}
?>
--EXPECTF--
