--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function leakApiToken(\Illuminate\Auth\TokenGuard $guard): void {
    $token = $guard->getTokenForRequest();

    echo $token;
}
?>
--EXPECTF--
%ATaintedHtml on line %d: Detected tainted HTML%A
