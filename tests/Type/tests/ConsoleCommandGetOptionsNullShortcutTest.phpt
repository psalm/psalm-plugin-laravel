--FILE--
<?php declare(strict_types=1);

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputOption;

// Regression for https://github.com/psalm/psalm-plugin-laravel/issues/1165.
// A null option shortcut (the "no shortcut" case, which is the common default) must not
// trigger a false-positive InvalidReturnType/InvalidReturnStatement on a getOptions() override.
// Laravel's native return type declares element index 1 as `string|non-empty-array<string>`,
// omitting the `|null` that Symfony's InputOption constructor (`string|array|null $shortcut = null`)
// accepts. Fixed by stubs/common/Console/Concerns/HasParameters.phpstub.
final class NullShortcutCommand extends Command
{
    /** @inheritDoc */
    #[\Override]
    protected function getOptions(): array
    {
        return [
            // index 1 = null shortcut (the reported bug): must be accepted
            ['nullShortcut', null, InputOption::VALUE_REQUIRED, 'desc', 'default'],
            // a real shortcut still resolves
            ['withShortcut', 's', InputOption::VALUE_NONE],
            // an InputOption object element still resolves
            new InputOption('asObject'),
        ];
    }

    // The stub re-declares the HasParameters trait with getOptions() only. Overriding the sibling
    // getArguments() guards that the partial re-declaration still MERGES with the reflected trait:
    // a wiped trait would raise InvalidOverride here. Uses a valid mode (not null); getArguments
    // index 1 is the mode, which does not reproduce #1165.
    /** @inheritDoc */
    #[\Override]
    protected function getArguments(): array
    {
        return [['argName', InputArgument::REQUIRED, 'desc']];
    }

    // Guards that the trait's internal consumer (which reads getArguments()/getOptions()) also
    // survives the merge; a wiped trait would surface as UndefinedMagicMethod on this call
    // (Command is Macroable, so a missing method routes through __call under sealAllMethods).
    public function probeMerge(): void
    {
        $this->specifyParameters();
    }
}
?>
--EXPECTF--
