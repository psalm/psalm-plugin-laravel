<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;
use Illuminate\Notifications\Messages\MailMessage;
use Livewire\Component;

final class LivewireForeignView extends Component
{
    public int $count = 0;

    public function render(): View
    {
        // Livewire merges the properties into the view render() returns, not into a mail this method
        // builds on the side: `$count` is missing there.
        (new MailMessage())->view('livewire.placeholder');

        return view('livewire.foreign');
    }
}
