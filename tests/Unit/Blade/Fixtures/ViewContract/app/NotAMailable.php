<?php

declare(strict_types=1);

namespace ViewContractFixture;

final class NotAMailable
{
    public string $title = "";

    public function render(): \Illuminate\Contracts\View\View
    {
        // The same public property on a class the framework merges nothing into.
        return view("mailable-missing");
    }
}
