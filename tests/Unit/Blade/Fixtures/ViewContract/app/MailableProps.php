<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Mail\Mailable;

final class MailableProps extends Mailable
{
    public int $count = 3;

    // Untyped: counts as supplied, never type-checked.
    public $loose;

    public static string $banner = "x";

    public function __construct(public readonly string $title, public readonly Thing $thing) {}

    public function build(): self
    {
        // Mailable::buildViewData() merges every public property into the view data, and Mailer adds
        // `$message`, so the template's five declarations are all supplied.
        return $this->view("mailable-props");
    }
}
