<?php

declare(strict_types=1);

namespace ViewContractFixture;

final class ChildMail extends ParentMail
{
    public function build(): self
    {
        // `parent` in ParentMail's property type is Mailable, not ParentMail.
        return $this->view('mail-parent');
    }
}
