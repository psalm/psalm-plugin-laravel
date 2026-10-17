<?php

declare(strict_types=1);

namespace App\View\Components;

use BladeIssueRemapFixture\BaseModel;
use Illuminate\View\Component;

/** Its view's `$book`/`$meta` are typed from these properties, though the prelude still writes `@var mixed` for them (#1909). */
final class MixedLeakCard extends Component
{
    /** @param array<string, mixed> $meta */
    public function __construct(public BaseModel $book, public array $meta = []) {}

    #[\Override]
    public function render(): \Illuminate\Contracts\View\View
    {
        return view('components.mixed-leak-card');
    }
}
