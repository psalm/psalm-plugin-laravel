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

    /** `Mailable::__call()` forwards an undeclared `withTotal()` to `with('total', ...)`. */
    public function viaMagicSetter(): self
    {
        $this->withTotal(1);

        return $this->view('mail-separate-with');
    }

    public function viaTextData(): self
    {
        $this->text('mail-separate-with', ['total' => 1]);

        return $this->view('mail-separate-with');
    }

    public function viaViewData(): self
    {
        $this->view('mail-separate-with', ['total' => 1]);

        return $this->view('mail-separate-with');
    }

    public function viaMarkdownData(): self
    {
        $this->markdown('mail-separate-with', ['total' => 1]);

        return $this->view('mail-separate-with');
    }

    /** The receiver chain is rooted at `$this`, though the call is not on `$this` itself. */
    public function viaRootedChain(): self
    {
        $this->subject('Receipt')->with('total', 1);

        return $this->view('mail-separate-with');
    }

    /** The rendering chain itself starts from a call on `$this`. */
    public function rootedBinderReceiver(): self
    {
        $this->with('total', 1);

        return $this->subject('Receipt')->view('mail-separate-with');
    }

    /** A `with()` below the binder is not modeled by the chain walk. */
    public function belowTheBinder(): self
    {
        return $this->with('total', 1)->view('mail-separate-with');
    }

    public function viaAlias(): self
    {
        $self = $this;
        $self->with('total', 1);

        return $this->view('mail-separate-with');
    }

    public function viaCallableArray(): self
    {
        $callable = [$this, 'with'];
        $callable('total', 1);

        return $this->view('mail-separate-with');
    }

    public function viaArgument(): self
    {
        (new \ArrayObject())->append($this);

        return $this->view('mail-separate-with');
    }

    public function viaDynamicProperty(): self
    {
        $property = 'viewData';
        $this->{$property}['total'] = 1;

        return $this->view('mail-separate-with');
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
