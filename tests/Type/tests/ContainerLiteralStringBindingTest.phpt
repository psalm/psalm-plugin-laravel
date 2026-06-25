--FILE--
<?php declare(strict_types=1);

// Covers the literal-string branch of ContainerResolver::resolveFromLiteralString() (#1178).
// When a container abstract resolves to a non-class string — Laravel's path bindings, e.g.
// `path.public` → the public directory — the resolver returns a string-typed Union. The #1178 fix
// swapped TLiteralString::make() (which throws on >1000-char concretes, crashing the run) for
// Type::getAtomicStringFromLiteral(); this locks in that the common short-string path still
// narrows to a usable string after that swap.
//
// The over-long crash itself can only fire when the booted app holds a >1000-char binding, which a
// phpt cannot inject without polluting the plugin's production Testbench boot. That path is
// verified by a real Psalm subprocess repro (see PR #1178): red (uncaught Throwable, run crashes)
// before the fix, green after.

function publicPathBindingResolvesToString(): string
{
    // app('path.public') resolves through the container to the public-directory string; the literal
    // value is environment-dependent, so we assert only that it satisfies the string return type.
    return app('path.public');
}
?>
--EXPECTF--
