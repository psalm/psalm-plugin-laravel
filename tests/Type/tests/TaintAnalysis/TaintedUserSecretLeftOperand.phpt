--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * The secret can be on either side of the comparison — both operands
 * are checked for secret taint.
 *
 * @psalm-taint-source user_secret
 */
function getUserApiKey(): string {
    return 'secret-key';
}

function verifyKey(string $input): bool {
    return getUserApiKey() === $input;
}
?>
--EXPECTF--
%ATaintedUserSecret on line %d: Detected tainted user secret leaking
