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
use PhpParser\Node\Expr\Variable;
use PhpParser\Node\Identifier;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Internal\MethodIdentifier;
use Psalm\LaravelPlugin\Util\ProxyMethodReturnTypeProvider;
use Psalm\Plugin\EventHandler\Event\MethodReturnTypeProviderEvent;
use Psalm\Plugin\EventHandler\MethodReturnTypeProviderInterface;
use Psalm\Type;
use Psalm\Type\Union;
use function strtolower;

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

    public static function getMethodReturnType(MethodReturnTypeProviderEvent $event) : ?Union
    {
        $source = $event->getSource();

        if (!$source instanceof StatementsAnalyzer) {
            return null;
        }

        $method_name_lowercase = $event->getMethodNameLowercase();

        // Relations are weird.
        // If a relation is proxying to the underlying builder, and the builder returns itself, the relation instead
        // returns an instance of ITSELF, rather than the instance of the builder. That explains this nonsense

        // If this method name is on the builder object, proxy it over there

        if ($method_name_lowercase === '__call') {
            $stmt = $event->getStmt();

            if (!($stmt instanceof MethodCall)) {
                return null;
            }

            $name = $stmt->name;

            if (!($name instanceof Identifier)) {
                return null;
            }

            $codebase = $source->getCodebase();
            /** @psalm-var lowercase-string $called_method_name_lowercase */
            $called_method_name_lowercase = $name->toLowerString();
            $type = null;

            foreach ([Builder::class, QueryBuilder::class] as $class) {
                $method_id = new MethodIdentifier($class, $called_method_name_lowercase);
                if ($codebase->methods->methodExists($method_id)) {
                    $self_class = null;
                    $type = $codebase->methods->getMethodReturnType(
                        $method_id,
                        $self_class,
                        null,
                        $event->getCallArgs()
                    );
                }
            }

            $template_type_parameters = $event->getTemplateTypeParameters();
            if (!$template_type_parameters) {
                return null;
            }

            if ($type instanceof Union) {
                if ($type->hasType('static')) {
                    return new Union([
                        new Type\Atomic\TGenericObject(
                            $event->getFqClasslikeName(),
                            $template_type_parameters
                        ),
                    ]);
                }

                return $type;
            }

            $fake_method_call = new MethodCall(
                new Variable('builder'),
                $method_name_lowercase,
                $event->getCallArgs()
            );

            $templateType = $template_type_parameters[0];

            $proxyType = new Type\Atomic\TGenericObject(Builder::class, [
                new Union([
                    new Type\Atomic\TNamedObject($templateType->getKey()),
                ]),
            ]);

            $type = ProxyMethodReturnTypeProvider::executeFakeCall($source, $fake_method_call, $event->getContext(), $proxyType);

            if (!$type) {
                return null;
            }

            foreach ($type->getAtomicTypes() as $type) {
                if ($type instanceof Type\Atomic\TNamedObject && $type->value === Builder::class) {
                    // ta-da. now we return "this" relation instance
                    return new Union([
                        new Type\Atomic\TGenericObject($event->getFqClasslikeName(), $template_type_parameters),
                    ]);
                }
            }
        }

        return null;
    }
}
