<?php declare(strict_types=1);

namespace Psalm\LaravelPlugin\ReturnTypeProvider;

use Illuminate\Support\Optional;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\Plugin\Hook\MethodExistenceProviderInterface;
use Psalm\Plugin\Hook\MethodReturnTypeProviderInterface;
use Psalm\StatementsSource;
use Psalm\Type;

final class OptionalReturnTypeProvider implements MethodReturnTypeProviderInterface
{

    public static function getClassLikeNames(): array
    {
        return [Optional::class];
    }

    public static function getMethodReturnType(StatementsSource $source, string $fq_classlike_name, string $method_name_lowercase, array $call_args, Context $context, CodeLocation $code_location, ?array $template_type_parameters = null, ?string $called_fq_classlike_name = null, ?string $called_method_name_lowercase = null): ?Type\Union
    {
        // todo: for some reason I can't seem  to get the proper context of User::getKeyName being called. The first
        // time this is invoked, `$method_name_lowercase` is properly "getKeyName", however there are no template type parameters
        // set. The second time this is invoked, template type parameters are set however the method name is __call
        // and I can't figure out how to find that "getKeyName" was being called
        var_dump($method_name_lowercase, $called_method_name_lowercase, $template_type_parameters);
        return null;

        if (!$template_type_parameters) {
            return null;
        }

        if ($method_name_lowercase !== '__call') {
            return null;
        }


        /**
         * @var \Psalm\Type\Union $templateType
         */
        $templateType = $template_type_parameters[0];

        // we want to find the original return type of the potentially non-null value, so remove null
        $templateType->removeType('null');

        foreach ($templateType->getAtomicTypes() as $type) {
            dump($type . '::' . $called_method_name_lowercase, $type . '::' . $method_name_lowercase);
            // try each of the types that were passed into the optional until we find a potential method return type
            $returnType = $source->getCodebase()->getMethodReturnType(
                $type . '::' . $called_method_name_lowercase,
                $fq_classlike_name
            );

            dump($returnType);

            if ($returnType) {
                break;
            }
        }

        if (!$returnType) {
            return null;
        }

        if (!$returnType->isNullable()) {
            // optional can always return null
            $returnType->addType(new Type\Atomic\TNull());
        }

        return $returnType;
    }
}
