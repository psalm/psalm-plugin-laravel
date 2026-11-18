--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
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
WeakHash on line %d: sha1() hashes with a weak algorithm: use hash() with a SHA-2 or SHA-3 one, password_hash() for passwords, or xxh3 where only speed matters
TaintedUserSecret on line %d: Detected tainted user secret leaking
