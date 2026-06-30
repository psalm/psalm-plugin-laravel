--FILE--
<?php declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

class HasInputGuardCommand extends Command
{
    /** @var string */
    protected $signature = 'example:guard {email}';

    public function handle(): int
    {
        // Defined argument → always-true guard. Literal-bool narrowing makes Psalm
        // report the redundant condition. This is the deliberately accepted trade-off
        // of full narrowing (parity with Larastan); kept here to lock in the behavior.
        if ($this->hasArgument('email')) {
            echo 'always runs';
        }

        // Undefined argument → always-false guard. Documents the false-direction issue
        // Psalm raises, so the "Both" trade-off is visible in deltas on real apps.
        if ($this->hasArgument('missing')) {
            echo 'never runs';
        }

        return 0;
    }
}
?>
--EXPECTF--
RedundantCondition on line %d: Operand of type true is always truthy
TypeDoesNotContainType on line %d: Operand of type false is always falsy
