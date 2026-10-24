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

    /** A `with()` above the binder is read as part of the chain, and does not supply `$total`. */
    public function ownChainLink(): self
    {
        return $this->view('mail-separate-with')->with('other', 1);
    }

    /** A `with`-prefixed method the class does declare is no magic setter. */
    public function declaredWithMethod(): self
    {
        $this->withSymfonyMessage(static function (): void {});

        return $this->view('mail-separate-with');
    }

    /** A fluent call on `$this` that adds no view data. */
    public function unrelatedFluentCall(): self
    {
        $this->subject('Receipt');

        return $this->view('mail-separate-with');
    }

    /** `text()` without a data argument adds nothing. */
    public function textWithoutData(): self
    {
        $this->text('mail-separate-with');

        return $this->view('mail-separate-with');
    }

    /** `$this` as a return value or a receiver does not escape. */
    public function returnsThis(): self
    {
        $this->view('mail-separate-with');

        return $this;
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
