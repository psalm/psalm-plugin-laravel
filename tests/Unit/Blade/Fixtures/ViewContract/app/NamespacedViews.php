<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;
use Illuminate\Http\Response;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\View as ViewFacade;

/** `pkg` is a `loadViewsFrom()` hint outside vendor/ (bootstrap/app.php), so its templates are compiled. */
final class NamespacedViews
{
    public function helper(): View
    {
        return view('pkg::widget', ['title' => 1, 'count' => 2]);
    }

    public function facade(): View
    {
        return ViewFacade::make('pkg::widget', ['title' => 'Ada']);
    }

    public function response(): Response
    {
        return response()->view('pkg::widget', ['title' => 'Ada', 'count' => 'two']);
    }

    public function mailMessage(): MailMessage
    {
        return (new MailMessage())->view('pkg::widget', ['title' => 1, 'count' => 2]);
    }

    public function correct(): View
    {
        return view('pkg::widget', ['title' => 'Ada', 'count' => 2]);
    }

    public function unknownHint(): View
    {
        return view('nope::widget', ['title' => 1]);
    }

    /** `notifications` is the framework's own hint, inside vendor/: never compiled, so never checked. */
    public function vendorHint(): View
    {
        return view('notifications::email', ['notRead' => 1]);
    }
}
