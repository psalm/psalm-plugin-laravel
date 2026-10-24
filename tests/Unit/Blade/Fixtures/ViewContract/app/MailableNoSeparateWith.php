<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Mail\Mailable;

/**
 * No `with()` on `$this` outside the rendering chain: `$total` is provably missing in each method.
 */
final class MailableNoSeparateWith extends Mailable
{
    public function build(): self
    {
        return $this->view('mail-separate-with');
    }

    /** A `with()` on another object adds nothing to this Mailable's data. */
    public function otherReceiver(): self
    {
        $other = new self();
        $other->with('total', 1);

        return $this->view('mail-separate-with');
    }

    /** The chain's own `with()` is read as part of the chain, and does not supply `$total`. */
    public function ownChainLink(): self
    {
        return $this->with('other', 1)->view('mail-separate-with');
    }

    /** The same closure shape as in the declining fixture, with nothing added to the data. */
    public function chainInClosure(): \Closure
    {
        return function (): self {
            return $this->view('mail-separate-with');
        };
    }

    /** `$this->with()` feeds this Mailable, not the one the chain renders. */
    public function otherMailable(self $mail): self
    {
        $this->with('total', 1);

        return $mail->view('mail-separate-with');
    }
}
