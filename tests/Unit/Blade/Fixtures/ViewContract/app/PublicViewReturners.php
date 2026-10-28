<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;

final class PublicViewReturners
{
    public function action(): View
    {
        return view('greeting');
    }

    public function route(): \Closure
    {
        return static function (): View {
            return view('greeting');
        };
    }

    // A closure inside a private method is deliberately still checked: closures are route-action shaped.
    private function helperWithClosure(): \Closure
    {
        return static function (): View {
            return view('greeting');
        };
    }
}
