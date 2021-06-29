<?php declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\Database\Eloquent\Relations\HasOneThrough;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Query\Builder as QueryBuilder;
use PhpParser\Node\Expr\MethodCall;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\Internal\MethodIdentifier;
use Psalm\LaravelPlugin\Util\ProxyMethodReturnTypeProvider;
use Psalm\Plugin\Hook\MethodReturnTypeProviderInterface;
use Psalm\StatementsSource;
use Psalm\Type;
use Psalm\Type\Union;
use function in_array;

final class RelationsMethodHandler implements MethodReturnTypeProviderInterface
{
    /**
     * @return array<string>
     */
    public static function getClassLikeNames(): array
    {
        return [
            Relation::class,
            BelongsTo::class,
            BelongsToMany::class,
            HasMany::class,
            HasManyThrough::class,
            HasOne::class,
            HasOneOrMany::class,
            HasOneThrough::class,
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
        if (!$source instanceof \Psalm\Internal\Analyzer\StatementsAnalyzer) {
            return null;
        }

        // Relations are weird.
        // If a relation is proxying to the underlying builder, and the builder returns itself, the relation instead
        // returns an instance of ITSELF, rather than the instance of the builder. That explains this nonsense

        // If this method name is on the builder object, proxy it over there

        if ($source->getCodebase()->methods->methodExists(new MethodIdentifier(Builder::class, $method_name_lowercase)) ||
            $source->getCodebase()->methods->methodExists(new MethodIdentifier(QueryBuilder::class, $method_name_lowercase))
        ) {
            if (!$template_type_parameters) {
                return null;
            }

            $fake_method_call = new MethodCall(
                new \PhpParser\Node\Expr\Variable('builder'),
                $method_name_lowercase,
                $call_args
            );

            /**
             * @var \Psalm\Type\Union $templateType
             */
            $templateType = $template_type_parameters[0];

            $proxyType = new Type\Atomic\TGenericObject(Builder::class, [
                new Union([
                    new Type\Atomic\TNamedObject($templateType->getKey()),
                ]),
            ]);

            $type = ProxyMethodReturnTypeProvider::executeFakeCall($source, $fake_method_call, $context, $proxyType);

            if (!$type) {
                return null;
            }

            foreach ($type->getAtomicTypes() as $type) {
                if ($type instanceof Type\Atomic\TNamedObject && $type->value === Builder::class) {
                    // ta-da. now we return "this" relation instance
                    return new Union([
                        new Type\Atomic\TGenericObject($fq_classlike_name, $template_type_parameters),
                    ]);
                }
            }
        }

        return null;
    }
}
