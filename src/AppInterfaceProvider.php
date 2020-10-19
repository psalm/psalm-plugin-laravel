<?php

namespace Psalm\LaravelPlugin;

use PhpParser;
use Psalm\Context;
use Psalm\CodeLocation;
use Psalm\Type;
use Psalm\StatementsSource;
use function get_class;

class AppInterfaceProvider implements
    \Psalm\Plugin\Hook\MethodReturnTypeProviderInterface,
    \Psalm\Plugin\Hook\MethodExistenceProviderInterface,
    \Psalm\Plugin\Hook\MethodVisibilityProviderInterface,
    \Psalm\Plugin\Hook\MethodParamsProviderInterface
{
    /** @return array<string> */
    public static function getClassLikeNames() : array
    {
        return [
            \Illuminate\Contracts\Foundation\Application::class,
            \Illuminate\Contracts\Container\Container::class
        ];
    }

    /**
     * @return ?bool
     */
    public static function doesMethodExist(
        string $fq_classlike_name,
        string $method_name_lowercase,
        StatementsSource $source = null,
        CodeLocation $code_location = null
    ) : ?bool {
        if ($method_name_lowercase === 'offsetget'
            || $method_name_lowercase === 'offsetset'
        ) {
            return true;
        }

        return null;
    }

    /**
     * @return ?bool
     */
    public static function isMethodVisible(
        StatementsSource $source,
        string $fq_classlike_name,
        string $method_name_lowercase,
        Context $context,
        CodeLocation $code_location = null
    ) : ?bool {
        if ($method_name_lowercase === 'offsetget'
            || $method_name_lowercase === 'offsetset'
        ) {
            return true;
        }

        return null;
    }

    /**
     * @param  array<PhpParser\Node\Arg>    $call_args
     * @return ?array<int, \Psalm\Storage\FunctionLikeParameter>
     */
    public static function getMethodParams(
        string $fq_classlike_name,
        string $method_name_lowercase,
        array $call_args = null,
        StatementsSource $statements_source = null,
        Context $context = null,
        CodeLocation $code_location = null
    ) : ?array {
        if ($statements_source) {
            if ($method_name_lowercase === 'offsetget' || $method_name_lowercase === 'offsetset') {
                return $statements_source->getCodebase()->getMethodParams(
                    get_class(ApplicationHelper::getApp()) . '::' . $method_name_lowercase
                );
            }
        }

        return null;
    }

    /**
     * @param  array<PhpParser\Node\Arg>    $call_args
     */
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
    ) : ?Type\Union {
        if ($method_name_lowercase === 'offsetget' || $method_name_lowercase === 'offsetset') {
            return $source->getCodebase()->getMethodReturnType(
                get_class(ApplicationHelper::getApp()) . '::' . $method_name_lowercase,
                $fq_classlike_name
            );
        }

        return null;
    }
}
