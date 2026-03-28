--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * != has the same timing characteristics as == — both are timing-unsafe.
 *
 * @psalm-taint-source user_secret
 */
function getUserPassword(): string {
    return 'password';
}

function rejectWrongPassword(string $input): void {
    if ($input != getUserPassword()) {
        throw new \RuntimeException('Wrong password');
    }
}
?>
--EXPECTF--
%ATaintedUserSecret on line %d: Detected tainted user secret leaking
