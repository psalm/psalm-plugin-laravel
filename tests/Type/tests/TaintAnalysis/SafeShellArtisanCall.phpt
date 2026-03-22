--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm.xml --taint-analysis
--FILE--
<?php declare(strict_types=1);

function runHardcodedArtisanCommand(): void {
    /** @var \Illuminate\Contracts\Console\Kernel $kernel */
    $kernel = app(\Illuminate\Contracts\Console\Kernel::class);
    $kernel->call('migrate:status');
    $kernel->queue('cache:clear');
}
?>
--EXPECTF--
