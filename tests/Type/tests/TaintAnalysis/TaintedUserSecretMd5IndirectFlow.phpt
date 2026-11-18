--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

/**
 * Indirect-flow case: the user_secret taint is laundered through a local
 * variable and a helper method before reaching md5(). Psalm must still
 * trace the flow and emit TaintedUserSecret at the md5() call site.
 *
 * --threads=1 is required: when this test is batched with the rest of the
 * --taint-analysis corpus under Psalm's default parallel workers, the
 * cross-method taint edge does not survive the worker merge. Standalone
 * runs detect it correctly. Same convention as the InlineValidate
 * cross-procedural tests.
 */
final class LegacyPasswordHasher
{
    public function digest(string $password): string
    {
        $normalised = strtolower(trim($password));

        return md5($normalised);
    }
}

function hashViaHelper(\Illuminate\Foundation\Auth\User $user): string {
    $password = $user->getAuthPassword();

    return (new LegacyPasswordHasher())->digest($password);
}
?>
--EXPECTF--
WeakHash on line %d: md5() hashes with a weak algorithm: use hash() with a SHA-2 or SHA-3 one, password_hash() for passwords, or xxh3 where only speed matters
TaintedUserSecret on line %d: Detected tainted user secret leaking
