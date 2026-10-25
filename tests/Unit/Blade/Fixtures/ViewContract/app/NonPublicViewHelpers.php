<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Contracts\View\View;

trait NonPublicViewHelperTrait
{
    private function traitView(): View
    {
        return view('greeting');
    }
}

final class NonPublicViewHelpers
{
    use NonPublicViewHelperTrait;

    public function show(): View
    {
        return $this->privateView()->with('name', 'Ada');
    }

    public function showProtected(): View
    {
        return $this->protectedView()->with('name', 'Ada');
    }

    public function showStatic(): View
    {
        return self::staticView()->with('name', 'Ada');
    }

    public function showTrait(): View
    {
        return $this->traitView()->with('name', 'Ada');
    }

    private function privateView(): View
    {
        return view('greeting');
    }

    protected function protectedView(): View
    {
        return view('greeting');
    }

    private static function staticView(): View
    {
        return view('greeting');
    }
}

function nonPublicHelperView(): View
{
    return view('greeting');
}
