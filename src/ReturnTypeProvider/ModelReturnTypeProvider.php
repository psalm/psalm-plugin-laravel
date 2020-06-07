<?php declare(strict_types=1);

namespace Psalm\LaravelPlugin\ReturnTypeProvider;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use PhpParser\Node\Expr\MethodCall;
use Psalm\CodeLocation;
use Psalm\Context;
use Psalm\Internal\MethodIdentifier;
use Psalm\LaravelPlugin\Plugin;
use Psalm\Plugin\Hook\MethodReturnTypeProviderInterface;
use Psalm\Plugin\Hook\PropertyExistenceProviderInterface;
use Psalm\Plugin\Hook\PropertyTypeProviderInterface;
use Psalm\StatementsSource;
use Psalm\Type;
use Psalm\Type\Union;
use function in_array;

final class ModelReturnTypeProvider implements MethodReturnTypeProviderInterface
{
    /**
     * @return array<string>
     */
    public static function getClassLikeNames(): array
    {
        return [Model::class];
    }

    public static function getMethodReturnType(StatementsSource $source, string $fq_classlike_name, string $method_name_lowercase, array $call_args, Context $context, CodeLocation $code_location, array $template_type_parameters = null, string $called_fq_classlike_name = null, string $called_method_name_lowercase = null)
    {
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

            $type = self::executeFakeCall($source, $fake_method_call, $context, $called_fq_classlike_name);
            return $type;
        }

        return null;
    }

    private static function executeFakeCall(
        \Psalm\Internal\Analyzer\StatementsAnalyzer $statements_analyzer,
        \PhpParser\Node\Expr\MethodCall $fake_method_call,
        Context $context,
        string $called_fq_classlike_name
    ) : ?Union {
        $old_data_provider = $statements_analyzer->node_data;
        $statements_analyzer->node_data = clone $statements_analyzer->node_data;

        $context = clone $context;
        $context->inside_call = true;

        $context->vars_in_scope['$builder'] = new Union([
            new Type\Atomic\TGenericObject(Builder::class, [
                new Union([
                    new Type\Atomic\TNamedObject($called_fq_classlike_name),
                ]),
            ]),
        ]);

        $suppressed_issues = $statements_analyzer->getSuppressedIssues();

        if (!in_array('PossiblyInvalidMethodCall', $suppressed_issues, true)) {
            $statements_analyzer->addSuppressedIssues(['PossiblyInvalidMethodCall']);
        }

        if (\Psalm\Internal\Analyzer\Statements\Expression\Call\MethodCallAnalyzer::analyze(
            $statements_analyzer,
            $fake_method_call,
            $context,
            false
        ) === false) {
            return null;
        }

        if (!in_array('PossiblyInvalidMethodCall', $suppressed_issues, true)) {
            $statements_analyzer->removeSuppressedIssues(['PossiblyInvalidMethodCall']);
        }

        $returnType = $statements_analyzer->node_data->getType($fake_method_call);

        $statements_analyzer->node_data = $old_data_provider;

        return $returnType;
    }
}
