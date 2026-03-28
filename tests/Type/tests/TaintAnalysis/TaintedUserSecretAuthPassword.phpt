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
%ATaintedUserSecret on line %d: Detected tainted user secret%A
