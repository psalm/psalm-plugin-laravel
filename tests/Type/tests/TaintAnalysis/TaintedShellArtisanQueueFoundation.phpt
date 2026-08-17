--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function queueArtisanCommandViaFoundationKernel(\Illuminate\Http\Request $request) {
    $command = $request->input('task');
    /** @var \Illuminate\Foundation\Console\Kernel $kernel */
    $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
    $kernel->queue($command);
}
?>
--EXPECTF--
MissingReturnType on line %d: Method queueArtisanCommandViaFoundationKernel does not have a return type, expecting void
MixedAssignment on line %d: Unable to determine the type that $command is being assigned to
MixedArgument on line %d: Argument 1 of Illuminate\Foundation\Console\Kernel::queue cannot be mixed, expecting string
TaintedShell on line %d: Detected tainted shell code
