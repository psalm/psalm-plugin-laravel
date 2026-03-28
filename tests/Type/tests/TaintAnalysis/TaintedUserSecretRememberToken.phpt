--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function leakRememberToken(\Illuminate\Foundation\Auth\User $user): void {
    $token = $user->getRememberToken();

    echo $token;
}
?>
--EXPECTF--
%ATaintedUserSecret on line %d: Detected tainted user secret%A
