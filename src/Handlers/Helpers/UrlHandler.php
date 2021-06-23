<?php declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Helpers;

use Illuminate\Contracts\Routing\UrlGenerator;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\Plugin\Hook\FunctionReturnTypeProviderInterface;
use Psalm\StatementsSource;
use Psalm\Type;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

final class UrlHandler implements FunctionReturnTypeProviderInterface
{
    public static function getFunctionIds(): array
    {
        return ['url'];
    }

    public static function getFunctionReturnType(
        StatementsSource $statements_source,
        string $function_id,
        array $call_args,
        Context $context,
        CodeLocation $code_location
    ) : ?Union {
        if (!$call_args) {
            return new Union([
                new TNamedObject(UrlGenerator::class),
            ]);
        }

        return Type::getString();
    }
}
