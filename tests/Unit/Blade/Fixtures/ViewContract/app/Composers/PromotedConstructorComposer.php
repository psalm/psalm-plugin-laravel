<?php

declare(strict_types=1);

namespace ViewContractFixture\Composers;

use Illuminate\View\View;

// A constructor that only stores its arguments leaves the key set provable.
final class PromotedConstructorComposer
{
    private int $limit;

    public function __construct(private readonly string $prefix, int $limit = 3)
    {
        $this->limit = $limit;
    }

    public function compose(View $view): void
    {
        $view->with('title', $this->prefix . $this->limit);
    }
}
