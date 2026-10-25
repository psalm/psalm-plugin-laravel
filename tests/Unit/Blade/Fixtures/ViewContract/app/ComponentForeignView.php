<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\View\Component;

final class ComponentForeignView extends Component
{
    public int $count = 0;

    public function render(): View
    {
        // A mail view built on the side here gets no component data either.
        (new MailMessage())->view('component-foreign-mail');

        // The properties reach the view `render()` returns.
        return view('component-foreign');
    }

    public function summary(): View
    {
        // Laravel merges `data()` into the view `render()` resolves and nothing else: a view built
        // here receives no `$count`.
        return view('component-foreign-side');
    }
}
