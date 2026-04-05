--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * strcasecmp() is also timing-unsafe.
 *
 * @psalm-taint-source user_secret
 */
function getSecret(): string {
    return 'secret';
}

function verify(string $input): bool {
    return strcasecmp($input, getSecret()) === 0;
}
?>
--EXPECTF--
%ATaintedUserSecret on line %d: Detected tainted user secret leaking
