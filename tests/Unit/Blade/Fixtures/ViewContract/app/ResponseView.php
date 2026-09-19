<?php

declare(strict_types=1);

namespace ViewContractFixture;

use Illuminate\Http\Response;

final class ResponseView
{
    public function render(): Response
    {
        return response()->view('greeting', ['name' => 123]);
    }
}
