<?php declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use PhpParser\Node\Expr\MethodCall;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\Internal\MethodIdentifier;
use Psalm\LaravelPlugin\Plugin;
use Psalm\LaravelPlugin\Util\ProxyMethodReturnTypeProvider;
use Psalm\Plugin\Hook\MethodReturnTypeProviderInterface;
use Psalm\Plugin\Hook\PropertyExistenceProviderInterface;
use Psalm\Plugin\Hook\PropertyTypeProviderInterface;
use Psalm\Plugin\Hook\AfterClassLikeVisitInterface;
use Psalm\StatementsSource;
use Psalm\Type;
use Psalm\Type\Union;
use function in_array;
use function strtolower;

final class ModelMethodHandler implements MethodReturnTypeProviderInterface, AfterClassLikeVisitInterface
{
    /**
     * @return array<string>
     */
    public static function getClassLikeNames(): array
    {
        return [Model::class];
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
    ) : ?Type\Union {
        if (!$source instanceof \Psalm\Internal\Analyzer\StatementsAnalyzer) {
            return null;
        }

        // proxy to builder object
        if ($method_name_lowercase === '__callstatic') {
            if (!$called_fq_classlike_name || !$called_method_name_lowercase) {
                return null;
            }
            $methodId = new MethodIdentifier($called_fq_classlike_name, $called_method_name_lowercase);

            $fake_method_call = new MethodCall(
                new \PhpParser\Node\Expr\Variable('builder'),
                $methodId->method_name,
                $call_args
            );

            $fakeProxy = new Type\Atomic\TGenericObject(Builder::class, [
                new Union([
                    new Type\Atomic\TNamedObject($called_fq_classlike_name),
                ]),
            ]);

            return ProxyMethodReturnTypeProvider::executeFakeCall($source, $fake_method_call, $context, $fakeProxy);
        }

        return null;
    }

    /**
     * @param  \Psalm\FileManipulation[] $file_replacements
     *
     * @return void
     */
    public static function afterClassLikeVisit(
        \PhpParser\Node\Stmt\ClassLike $stmt,
        \Psalm\Storage\ClassLikeStorage $storage,
        \Psalm\FileSource $statements_source,
        \Psalm\Codebase $codebase,
        array &$file_replacements = []
    ) {
        if ($stmt instanceof \PhpParser\Node\Stmt\Class_
            && !$storage->abstract
            && isset($storage->parent_classes[strtolower(Model::class)])
        ) {
            unset(
                $storage->pseudo_static_methods['newmodelquery'],
                $storage->pseudo_static_methods['newquery'],
                $storage->pseudo_static_methods['query']
            );
        }
    }
}
