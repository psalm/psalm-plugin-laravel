--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

/**
 * hashPasswordForCookie() escapes user_secret but preserves other taint kinds
 * via @psalm-flow. Input taint (which includes html) must survive.
 */
function hashPreservesHtmlTaint(\Illuminate\Http\Request $request, \Illuminate\Auth\SessionGuard $guard): void {
    /** @var string $input */
    $input = $request->input('data');

    $hashed = $guard->hashPasswordForCookie($input);

    echo $hashed;
}
?>
--EXPECTF--
%ATaintedHtml on line %d: Detected tainted HTML%A
