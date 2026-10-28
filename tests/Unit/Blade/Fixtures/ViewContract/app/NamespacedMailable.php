<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Mail\Mailable;

final class NamespacedMailable extends Mailable
{
    public function build(): self
    {
        return $this->markdown('pkg::widget', ['title' => 'Ada', 'count' => 'two']);
    }
}
