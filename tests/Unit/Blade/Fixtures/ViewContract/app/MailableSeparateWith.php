<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Mail\Mailable;

/**
 * Every method adds `$total` to the Mailable somewhere other than the rendering chain, so none of the
 * `view()` calls proves `$total` absent.
 */
final class MailableSeparateWith extends Mailable
{
    public function build(): self
    {
        $this->with('total', 1);

        return $this->view('mail-separate-with');
    }

    public function viaArray(): self
    {
        $this->with(['total' => 1]);

        return $this->view('mail-separate-with');
    }

    public function afterTheChain(): self
    {
        $this->view('mail-separate-with');
        $this->with('total', 1);

        return $this;
    }

    public function insideClosure(): self
    {
        $add = function (): void {
            $this->with('total', 1);
        };
        $add();

        return $this->view('mail-separate-with');
    }

    public function viaViewDataProperty(): self
    {
        $this->viewData['total'] = 1;

        return $this->view('mail-separate-with');
    }

    public function viaDynamicMethodName(string $method): self
    {
        $this->{$method}('total', 1);

        return $this->view('mail-separate-with');
    }

    public function insideNestedClosure(): self
    {
        $this->view('mail-separate-with');

        return \tap($this, function (): void {
            $this->with('total', 1);
        });
    }

    public function chainedAndSeparate(): self
    {
        $this->with('total', 1);

        return $this->with('other', 2)->view('mail-separate-with');
    }

    /** The rendering chain sits in a closure; the enclosing method adds the data. */
    public function chainInClosure(): \Closure
    {
        $this->with('total', 1);

        return function (): self {
            return $this->view('mail-separate-with');
        };
    }
}
