<?php

namespace Psalm\LaravelPlugin\Handlers\Helpers;

use Illuminate\Config\Repository;
use Psalm\LaravelPlugin\Providers\ApplicationProvider;
use Psalm\Plugin\EventHandler\Event\FunctionReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\FunctionReturnTypeProviderInterface;
use Psalm\Type\Atomic\TArray;
use Psalm\Type\Atomic\TArrayKey;
use Psalm\Type\Atomic\TBool;
use Psalm\Type\Atomic\TClosedResource;
use Psalm\Type\Atomic\TFloat;
use Psalm\Type\Atomic\TLiteralFloat;
use Psalm\Type\Atomic\TLiteralInt;
use Psalm\Type\Atomic\TLiteralString;
use Psalm\Type\Atomic\TMixed;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Atomic\TResource;
use Psalm\Type\Union;

use function gettype;
use function get_class;

class ConfigHandler implements FunctionReturnTypeProviderInterface
{
    public static function getFunctionIds(): array
    {
        return ['config'];
    }

    public static function getFunctionReturnType(FunctionReturnTypeProviderEvent $event): ?Union
    {
        // we're going to attempt some dynamic analysis to tighten the actual return type here.
        // this could be done statically, but it's quicker + easier to do this dynamically.
        // PRs to make this static in the future more than welcome!
        $call_args = $event->getCallArgs();
        if (!isset($call_args[0])) {
            return new Union([
                new TNamedObject(Repository::class),
            ]);
        }

        $argumentType = $call_args[0]->value;

        if (!isset($argumentType->value)) {
            return null;
        }

        $argumentValue = $argumentType->value;

        try {
            // dynamic analysis
            $returnValue = ApplicationProvider::getApp()->make('config')->get($argumentValue);
        } catch (\Throwable $t) {
            return null;
        }

        // turn actual return value into a psalm type. there's probably a helper in psalm to do this, but i couldn't find one
        switch (gettype($returnValue)) {
            case 'boolean':
                $type = new TBool();
                break;
            case 'integer':
                $type = new TLiteralInt($returnValue);
                break;
            case 'double':
                $type = new TLiteralFloat($returnValue);
                break;
            case 'string':
                $type = new TLiteralString($returnValue);
                break;
            case 'array':
                $type = new TArray([
                    new Union([new TArrayKey()]),
                    new Union([new TMixed()]),
                ]);
                break;
            case 'object':
                $type = new TNamedObject(get_class($returnValue));
                break;
            case 'resource':
                $type = new TResource();
                break;
            case 'resource (closed)':
                $type = new TClosedResource();
                break;
            case 'NULL':
                if (isset($call_args[1])) {
                    return $event->getStatementsSource()->getNodeTypeProvider()->getType($call_args[1]->value);
                }
                $type = new TNull();
                break;
            case 'unknown type':
            default:
                $type = new TMixed();
                break;
        }

        return new Union([
            $type,
        ]);
    }
}
