<?php

declare(strict_types=1);

namespace BladeIssueRemapFixture;

/**
 * A project file naming a genuinely absent class in CODE POSITION. Its UndefinedClass must
 * survive the literal-queueing fix: speculative shadow-literal candidates must never mask a
 * real miss. (The absent class is never a shadow literal, so this is a baseline guard on the
 * whole mechanism, not a pin on the `store_failure` flag specifically.)
 */
final class GenuineMiss
{
    public function go(): void
    {
        new \Totally\Missing\Klass();
    }
}
