--SKIPIF--
<?php
require getcwd() . '/vendor/autoload.php';
\Tests\Psalm\LaravelPlugin\Type\LaravelAiCapability::skipUnlessInstalled();
--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis --threads=1
--FILE--
<?php declare(strict_types=1);

// The stub re-declaration of Tools\Request must keep the class sealed: Macroable::__call() throws
// for anything that is not a registered macro, so an unknown method is a runtime error.
// get() and input() exist on Illuminate\Http\Request, not here.
function toolRequestUndeclared(\Laravel\Ai\Tools\Request $request): void {
    $request->get('q');
    $request->input('q');
}

// Declared, trait (InteractsWithData, Conditionable) and ArrayAccess members keep resolving.
function toolRequestDeclared(\Laravel\Ai\Tools\Request $request): array {
    $string = $request->string('q');
    /** @psalm-check-type-exact $string = \Illuminate\Support\Stringable */
    $has = $request->has('q');
    /** @psalm-check-type-exact $has = bool */
    $all = $request->all();
    /** @psalm-check-type-exact $all = array<string, mixed> */
    $self = $request->when(true, static fn (\Laravel\Ai\Tools\Request $r): \Laravel\Ai\Tools\Request => $r);
    /** @psalm-check-type $self = \Laravel\Ai\Tools\Request */
    // Registered in tests/Type/macro-fixtures.php; MacroHandler declares it as a pseudo-method.
    $macro = $request->testToolRequestMacro('q');
    /** @psalm-check-type-exact $macro = bool */

    return [$request->exists('q'), $string, $has, $all, $self, $macro, $request['q'], $request->toolCallId()];
}
?>
--EXPECTF--
UndefinedMagicMethod on line %d: Magic method Laravel\Ai\Tools\Request::get does not exist
UndefinedMagicMethod on line %d: Magic method Laravel\Ai\Tools\Request::input does not exist
