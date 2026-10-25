<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Mail\Mailable;

final class MailableAdditional extends Mailable
{
    public function build(): self
    {
        // The override may replace the passed 'title'; 'junk' is still a passed key nothing reads.
        return $this->view('mail-additional', ['title' => 123, 'junk' => 1]);
    }

    /**
     * @return array<string, mixed>
     */
    protected function additionalMessageData(): array
    {
        return ['title' => 'ready'];
    }
}
