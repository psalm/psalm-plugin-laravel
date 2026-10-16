<?php

declare(strict_types=1);

namespace App\View\Components;

use BladeIssueRemapFixture\BaseModel;
use Illuminate\View\Component;

/** Its view reports an issue while Psalm still scans, before this class seeds `$book` (#1909). */
final class MixedLeakScanCard extends Component
{
    public function __construct(public BaseModel $book) {}

    #[\Override]
    public function render(): \Illuminate\Contracts\View\View
    {
        return view('components.mixed-leak-scan-card');
    }
}
