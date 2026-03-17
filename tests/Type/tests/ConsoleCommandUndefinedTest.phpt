--FILE--
<?php declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

class UndefinedArgCommand extends Command
{
    /** @var string */
    protected $signature = 'test:undefined {email}';

    public function handle(): int
    {
        // This argument is not defined in the signature — should emit UndefinedConsoleInput
        $this->argument('nonexistent');

        // This option is not defined in the signature — should emit UndefinedConsoleInput
        $this->option('nonexistent');

        return 0;
    }
}
?>
--EXPECT--
UndefinedConsoleInput on line 16: Console argument 'nonexistent' is not defined in UndefinedArgCommand's signature
UndefinedConsoleInput on line 19: Console option 'nonexistent' is not defined in UndefinedArgCommand's signature
