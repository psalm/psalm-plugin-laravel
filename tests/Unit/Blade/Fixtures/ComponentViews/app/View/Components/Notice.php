<?php

declare(strict_types=1);

namespace App\View\Components;

/** Inherits render(): the view is still this class's own. */
final class Notice extends BaseNotice
{
    public string $message = 'Saved';
}
