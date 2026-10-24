<?php

declare(strict_types=1);

namespace App\Http;

use App\View\Components\Constructed;
use App\View\Components\ConstructedPlain;
use App\View\Components\TraitD;
use Illuminate\Http\Request;

final class Controller
{
    public function promoted(Request $request): Constructed
    {
        return new Constructed((string) $request->input('q'));
    }

    public function plain(Request $request): ConstructedPlain
    {
        return new ConstructedPlain((string) $request->input('q'));
    }

    public function trait(Request $request): TraitD
    {
        return new TraitD((string) $request->input('q'));
    }
}
