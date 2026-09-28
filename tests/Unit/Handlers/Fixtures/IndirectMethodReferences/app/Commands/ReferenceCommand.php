<?php

declare(strict_types=1);

namespace IndirectMethodReferencesFixture\Commands;

use Illuminate\Console\Command;
use IndirectMethodReferencesFixture\Dependencies\CommandDependency;
use IndirectMethodReferencesFixture\Dependencies\CommandHelperDependency;

final class ReferenceCommand extends Command
{
    public function handle(CommandDependency $dependency): int
    {
        return self::SUCCESS;
    }

    /** Only handle() is a console entrypoint; a public helper stays reportable. */
    public function helper(CommandHelperDependency $dependency): int
    {
        return self::SUCCESS;
    }
}
