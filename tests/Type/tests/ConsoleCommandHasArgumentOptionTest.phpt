--FILE--
<?php declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;

class HasInputCommand extends Command
{
    /** @var string */
    protected $signature = 'example:has
        {email : The user email}
        {--F|force : Force flag}
    ';

    public function handle(): int
    {
        // Defined argument → literal true
        $_hasEmail = $this->hasArgument('email');
        /** @psalm-check-type-exact $_hasEmail = true */

        // Undefined argument → literal false (no InvalidConsoleArgumentName: existence-testing is valid)
        $_hasMissingArg = $this->hasArgument('missing');
        /** @psalm-check-type-exact $_hasMissingArg = false */

        // Defined option (long name) → literal true
        $_hasForce = $this->hasOption('force');
        /** @psalm-check-type-exact $_hasForce = true */

        // A shortcut ('F') is NOT a valid hasOption() name — Symfony's Input::hasOption()
        // matches long names and negations only, never shortcuts → literal false
        $_hasForceShortcut = $this->hasOption('F');
        /** @psalm-check-type-exact $_hasForceShortcut = false */

        // Inherited global option (--verbose) → literal true
        $_hasVerbose = $this->hasOption('verbose');
        /** @psalm-check-type-exact $_hasVerbose = true */

        // Negation of the inherited negatable --ansi (--no-ansi is a valid name) → literal true
        $_hasNoAnsi = $this->hasOption('no-ansi');
        /** @psalm-check-type-exact $_hasNoAnsi = true */

        // Undefined option → literal false (no InvalidConsoleOptionName emitted)
        $_hasMissingOpt = $this->hasOption('missing');
        /** @psalm-check-type-exact $_hasMissingOpt = false */

        // Dynamic (non-literal) name → cannot narrow → declared bool
        $dynamic = $this->argument('email');
        $_hasDynamic = $this->hasArgument($dynamic);
        /** @psalm-check-type-exact $_hasDynamic = bool */

        return 0;
    }
}

/**
 * Legacy-style command with no parseable $signature → the definition is unavailable, so
 * hasArgument()/hasOption() gracefully degrade to the declared bool (no narrowing, no issue).
 */
class NoSignatureCommand extends Command
{
    /** @var string */
    protected $name = 'example:nosig';

    public function handle(): int
    {
        $_hasArg = $this->hasArgument('whatever');
        /** @psalm-check-type-exact $_hasArg = bool */

        $_hasOpt = $this->hasOption('whatever');
        /** @psalm-check-type-exact $_hasOpt = bool */

        return 0;
    }
}
?>
--EXPECTF--
