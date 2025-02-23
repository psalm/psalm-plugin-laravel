<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Eloquent;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Stmt\Class_;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\MethodIdentifier;
use Psalm\LaravelPlugin\Util\ProxyMethodReturnTypeProvider;
use Psalm\Plugin\EventHandler\AfterClassLikeVisitInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeVisitEvent;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type;
use Psalm\Type\Union;

use function is_string;
use function strtolower;

final class ModelMethodHandler implements MethodReturnTypeProviderInterface, AfterClassLikeVisitInterface
{
    /** @inheritDoc */
    #[\Override]
    public static function getClassLikeNames(): array
    {
        return [Model::class];
    }

    /** @inheritDoc */
    #[\Override]
    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event): ?Type\Union
    {
        $source = $event->getSource();

        if (!$source instanceof StatementsAnalyzer) {
            return null;
        }

        $called_fq_classlike_name = $event->getCalledFqClasslikeName();

        if (! is_string($called_fq_classlike_name)) {
            return null;
        }

        // Model::query()
        if ($event->getMethodNameLowercase() === 'query') {
            return new Union([
                new Type\Atomic\TGenericObject(Builder::class, [
                    new Union([
                        new Type\Atomic\TNamedObject($called_fq_classlike_name),
                    ]),
                ])
            ]);
        }

        // proxy to builder object
        if ($event->getMethodNameLowercase() === '__callstatic') {
            $called_method_name_lowercase = $event->getCalledMethodNameLowercase();

            if ($called_method_name_lowercase === null) {
                return null;
            }

            $methodId = new MethodIdentifier($called_fq_classlike_name, $called_method_name_lowercase);

            $fake_method_call = new MethodCall(
                new Variable('builder'),
                $methodId->method_name,
                $event->getCallArgs()
            );

            $fakeProxy = new Type\Atomic\TGenericObject(Builder::class, [
                new Union([
                    new Type\Atomic\TNamedObject($called_fq_classlike_name),
                ]),
            ]);

            return ProxyMethodReturnTypeProvider::executeFakeCall($source, $fake_method_call, $event->getContext(), $fakeProxy);
        }

        return null;
    }

    /** @inheritDoc */
    #[\Override]
    public static function afterClassLikeVisit(AfterClassLikeVisitEvent $event): void
    {
        $storage = $event->getStorage();
        if (
            $event->getStmt() instanceof Class_
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
