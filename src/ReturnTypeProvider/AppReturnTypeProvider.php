<?php declare(strict_types=1);

namespace Psalm\LaravelPlugin\ReturnTypeProvider;

use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\Internal\MethodIdentifier;
use Psalm\LaravelPlugin\ApplicationHelper;
use Psalm\LaravelPlugin\ContainerResolver;
use Psalm\Plugin\Hook\FunctionReturnTypeProviderInterface;
use Psalm\Plugin\Hook\MethodReturnTypeProviderInterface;
use Psalm\StatementsSource;
use Psalm\Type;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;
use function get_class;
use function array_filter;
use function in_array;

final class AppReturnTypeProvider implements FunctionReturnTypeProviderInterface, MethodReturnTypeProviderInterface
{

    /**
     * @return array<array-key, lowercase-string>
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
                new TNamedObject(get_class(ApplicationHelper::getApp())),
            ]);
        }

        return ContainerResolver::resolvePsalmTypeFromApplicationContainerViaArgs($statements_source->getNodeTypeProvider(), $call_args) ?? Type::getMixed();
    }

    public static function getClassLikeNames(): array
    {
        return [get_class(ApplicationHelper::getApp())];
    }

    public static function getMethodReturnType(
        StatementsSource $source,
        string $fq_classlike_name,
        string $method_name_lowercase,
        array $call_args,
        Context $context,
        CodeLocation $code_location,
        ?array $template_type_parameters = null,
        ?string $called_fq_classlike_name = null,
        ?string $called_method_name_lowercase = null
    ) : ?Type\Union {
        // lumen doesn't have the likes of makeWith, so we will ensure these methods actually exist on the underlying
        // app contract
        $methods = array_filter(['make', 'makewith'], function (string $methodName) use ($source, $fq_classlike_name) {
            $methodId = new MethodIdentifier($fq_classlike_name, $methodName);
            return $source->getCodebase()->methodExists($methodId);
        });

        if (!in_array($method_name_lowercase, $methods)) {
            return null;
        }

        return ContainerResolver::resolvePsalmTypeFromApplicationContainerViaArgs($source->getNodeTypeProvider(), $call_args);
    }
}
