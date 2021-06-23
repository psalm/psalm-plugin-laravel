<?php declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Helpers;

use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\LaravelPlugin\Providers\ApplicationProvider;
use Psalm\Plugin\Hook\FunctionReturnTypeProviderInterface;
use Psalm\Plugin\Hook\MethodReturnTypeProviderInterface;
use Psalm\StatementsSource;
use Psalm\Type\Atomic\TLiteralString;
use Psalm\Type\Union;
use function get_class;
use function in_array;
use function is_string;

final class PathHandler implements FunctionReturnTypeProviderInterface, MethodReturnTypeProviderInterface
{
    public static function getFunctionIds(): array
    {
        return ['app_path', 'base_path', 'config_path', 'database_path',  'resource_path', 'public_path', 'storage_path'];
    }

    public static function getFunctionReturnType(
        StatementsSource $statements_source,
        string $function_id,
        array $call_args,
        Context $context,
        CodeLocation $code_location
    ) : ?Union {
        /**
         * @psalm-suppress MissingClosureReturnType
         */
        return self::resolveReturnType($call_args, function (array $args = []) use ($function_id) {
            return $function_id(...$args);
        });
    }

    public static function getClassLikeNames(): array
    {
        return [
            get_class(ApplicationProvider::getApp()),
        ];
    }

    public static function getMethodReturnType(
        StatementsSource $source,
        string $fq_classlike_name,
        string $method_name_lowercase,
        array $call_args,
        Context $context,
        CodeLocation $code_location,
        array $template_type_parameters = null,
        string $called_fq_classlike_name = null,
        string $called_method_name_lowercase = null
    ) : ?Union {
        $methods = ['path', 'basepath', 'configpath', 'databasepath', 'resourcepath'];

        if (!in_array($method_name_lowercase, $methods)) {
            return null;
        }

        /**
         * @psalm-suppress MissingClosureReturnType
         */
        return self::resolveReturnType($call_args, function (array $args = []) use ($method_name_lowercase) {
            return ApplicationProvider::getApp()->{$method_name_lowercase}(...$args);
        });
    }

    private static function resolveReturnType(array $call_args, \Closure $closure): ?Union
    {
        // we're going to do some dynamic analysis here. Were going to invoke the closure that is wrapping the
        // app method or the global function in order to determine the literal string path that is returned
        // so that we can inform psalm of where the files live.
        $argument = '';

        if (isset($call_args[0])) {
            $argumentType = $call_args[0]->value;
            if (isset($argumentType->value)) {
                $argument = $argumentType->value;
            }
        }

        $result = $closure([$argument]);

        if (!$result || !is_string($result)) {
            return null;
        }

        return new Union([
            new TLiteralString($result),
        ]);
    }
}
