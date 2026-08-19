--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function leakPasswordHash(\Illuminate\Foundation\Auth\User $user): void {
    $hash = $user->getAuthPassword();

    echo $hash;
}
?>
--EXPECTF--
TaintedUserSecret on line %d: Detected tainted user secret leaking
