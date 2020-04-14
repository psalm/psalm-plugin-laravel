<?php declare(strict_types=1);

namespace Psalm\LaravelPlugin\ReturnTypeProvider;

use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\Plugin\Hook\FunctionReturnTypeProviderInterface;
use Psalm\StatementsSource;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

final class AppReturnTypeProvider implements FunctionReturnTypeProviderInterface
{

    /**
     * @return array<string>
     */
    public static function getFunctionIds(): array
    {
        return ['app', 'resolve'];
    }

    /**
     * @param  array<\PhpParser\Node\Arg> $call_args
     */
    public static function getFunctionReturnType(StatementsSource $statements_source, string $function_id, array $call_args, Context $context, CodeLocation $code_location): ?Union
    {
        if (!$call_args) {
            return new Union([
                new TNamedObject(\Illuminate\Contracts\Foundation\Application::class),
            ]);
        }

        // @todo: this should really proxy to \Illuminate\Foundation\Application::make, but i was struggling with that
        return new Union([
            new TNamedObject($call_args[0]->value),
        ]);
    }
}
