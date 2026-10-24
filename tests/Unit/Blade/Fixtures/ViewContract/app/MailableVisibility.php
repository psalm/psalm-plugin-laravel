<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Mail\Mailable;

final class MailableVisibility extends Mailable
{
    public string $pub = '';

    protected string $prot = '';

    private string $priv = '';

    // Reflection IS_PUBLIC lists a static property too, so Mailable::buildViewData() hands it over.
    public static string $stat = '';

    public function build(): self
    {
        // Only `pub` and `stat` reach the view: a protected or private property is not public.
        return $this->view('mailable-visibility');
    }
}
