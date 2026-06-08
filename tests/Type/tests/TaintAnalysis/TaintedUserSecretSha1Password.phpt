--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * Cross-coverage of the (kind, sink) matrix: user_secret flows into sha1()
 * (the user_secret/sha1 cell complements TaintedUserSecretMd5Password and
 * TaintedSystemSecretSha1ApiKey).
 */
function legacySha1Password(\Illuminate\Foundation\Auth\User $user): string {
    return sha1($user->getAuthPassword());
}
?>
--EXPECTF--
%ATaintedUserSecret on line %d: Detected tainted user secret leaking%A
