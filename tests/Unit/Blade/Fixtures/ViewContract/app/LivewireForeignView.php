<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Notifications\Messages\MailMessage;
use Livewire\Component;

final class LivewireForeignView extends Component
{
    public int $count = 0;

    public string $label = "";

    public $loose;

    public function render(): \Illuminate\Contracts\View\View
    {
        // Livewire merges the properties into the view render() returns, not into a mail this method
        // builds on the side: `$count` is missing here.
        (new MailMessage())->view("livewire-placeholder");

        return view("livewire-counter");
    }
}
