<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Rules;

use Illuminate\Database\Eloquent\Model;
use PhpParser\Node\Arg;
use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Expr\StaticCall;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use Psalm\Codebase;
use Psalm\CodeLocation;
use Psalm\Exception\UnpopulatedClasslikeException;
use Psalm\IssueBuffer;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadata;
use Psalm\LaravelPlugin\Handlers\Eloquent\Metadata\ModelMetadataRegistry;
use Psalm\LaravelPlugin\Handlers\Eloquent\Support\ModelPropertyResolver;
use Psalm\LaravelPlugin\Handlers\Http\RequestInputProvenance;
use Psalm\LaravelPlugin\Issues\MassAssignmentFromRequest;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\StatementsSource;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Union;

/**
 * Opt-in rule (#1574): flags an Eloquent mass-assignment call whose argument is PROVEN to be raw,
 * unfiltered request data — `$request->all()`, `request()->all()`, the `query`/`request` `InputBag`
 * properties, or `json()`, read directly or through one local variable assignment (see
 * {@see \Psalm\LaravelPlugin\Handlers\Http\RequestInputProvenance}). An attacker who controls the
 * request body can add any column this way, including ones the form never exposed.
 *
 * `forceFill()` / `forceCreate()` / `forceCreateQuietly()` are deliberately NOT in scope — they
 * already bypass guarding by explicit author choice, so flagging them is noise, not a new finding.
 *
 * Receiver forms: a model instance, a model static call (`self`/`static` included), and the
 * `Builder<TModel>` / `Relation<TRelatedModel, ...>` forwarding form (`$post->comments()->create(...)`,
 * `Model::query()->update(...)`) via {@see ModelPropertyResolver::resolveExactlyOneModelClass()}.
 * A receiver that does not resolve to exactly one known model class is skipped — provenance-restrict
 * over the CALL, type-restrict over the RECEIVER, exactly like {@see UnknownModelAttributeHandler}
 * and {@see UndefinedBuilderMethodHandler}.
 */
final class MassAssignmentFromRequestHandler implements AfterExpressionAnalysisInterface
{
    /**
     * `forceFill`/`forceCreate`/`forceCreateQuietly` are excluded on purpose (see class docblock).
     *
     * @var array<lowercase-string, true>
     */
    private const MASS_ASSIGNMENT_METHODS = [
        'create' => true,
        'createquietly' => true,
        'fill' => true,
        'update' => true,
        'updatequietly' => true,
        'updateorfail' => true,
    ];

    /** @inheritDoc */
    #[\Override]
    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        $expr = $event->getExpr();

        if (!$expr instanceof StaticCall && !$expr instanceof MethodCall) {
            return null;
        }

        if (!$expr->name instanceof Identifier) {
            return null;
        }

        if (!isset(self::MASS_ASSIGNMENT_METHODS[\strtolower($expr->name->name)])) {
            return null;
        }

        // The attribute map is the first positional argument (`$attributes`). Bail on a
        // first-class callable, argument unpacking, or a named argument for another parameter.
        $arg = $expr->args[0] ?? null;

        if (!$arg instanceof Arg || $arg->unpack) {
            return null;
        }

        if ($arg->name instanceof Identifier && $arg->name->name !== 'attributes') {
            return null;
        }

        if (!RequestInputProvenance::isProven($arg->value, $event)) {
            return null;
        }

        $codebase = $event->getCodebase();

        $modelClass = $expr instanceof StaticCall
            ? self::staticReceiverModel($expr, $event->getStatementsSource(), $codebase)
            : self::instanceReceiverModel($expr, $event, $codebase);

        if ($modelClass === null) {
            return null;
        }

        $shortName = self::shortClassName($modelClass);
        $methodName = $expr->name->name;

        $message = "{$shortName}::{$methodName}() mass-assigns raw request data. An attacker can add "
            . "any key to the request and have it written to {$shortName} — use \$request->validated() "
            . 'or $request->safe()->only([...]) instead.';

        if (self::isProvablyUnguarded($modelClass)) {
            $message .= " {$shortName} declares no \$fillable or \$guarded restriction, so every "
                . 'column is writable this way.';
        }

        IssueBuffer::accepts(
            new MassAssignmentFromRequest($message, new CodeLocation($event->getStatementsSource(), $expr)),
            $event->getStatementsSource()->getSuppressedIssues(),
        );

        return null;
    }

    /**
     * A model is provably unguarded when its runtime configuration is fully known and it declares
     * neither a `$fillable` allowlist nor a `$guarded` denylist — every column is mass-assignable
     * (see #1574 design brief §D; `$guarded = false` is normalized to `[]` upstream in the registry
     * builder, so that idiom is covered too).
     *
     * @param class-string<Model> $modelClass
     *
     * @psalm-external-mutation-free
     */
    private static function isProvablyUnguarded(string $modelClass): bool
    {
        $metadata = ModelMetadataRegistry::for($modelClass);

        return $metadata instanceof ModelMetadata
            && $metadata->isComplete(ModelMetadata::SECTION_RUNTIME_CONFIGURATION)
            && $metadata->guarded === []
            && $metadata->fillable === [];
    }

    /**
     * Resolve the model FQCN named on the left of a static call, or null when the receiver is not a
     * (resolvable) Eloquent model. `self`/`static` are resolved against the enclosing class, mirroring
     * {@see UnknownModelAttributeHandler::staticReceiverModel()}.
     *
     * @return class-string<Model>|null
     */
    private static function staticReceiverModel(StaticCall $expr, StatementsSource $source, Codebase $codebase): ?string
    {
        if (!$expr->class instanceof Name) {
            return null;
        }

        $className = self::resolveClassName($expr->class, $source);

        if ($className === null || !self::isModelSubclass($className, $codebase)) {
            return null;
        }

        return $className;
    }

    /** Mirrors {@see UnknownModelAttributeHandler::resolveClassName()}. */
    private static function resolveClassName(Name $class, StatementsSource $source): ?string
    {
        if ($class->isSpecialClassName()) {
            return match ($class->toLowerString()) {
                'self', 'static' => $source->getFQCLN(),
                default => null,
            };
        }

        /** @psalm-var ?string $resolved */
        $resolved = $class->getAttribute('resolvedName');

        return \is_string($resolved) ? $resolved : $class->toString();
    }

    /**
     * Resolve the model FQCN of an instance call's receiver. Tries a plain model receiver first
     * (every atomic the SAME single Eloquent model, mirroring
     * {@see UnknownModelAttributeHandler::instanceReceiverModel()}), then falls back to the
     * `Builder<TModel>` / `Relation<TRelatedModel, ...>` forwarding form via
     * {@see ModelPropertyResolver::resolveExactlyOneModelClass()} (template index 0 is the related
     * model for both generics — #1574 design brief §C).
     *
     * @return class-string<Model>|null
     */
    private static function instanceReceiverModel(MethodCall $expr, AfterExpressionAnalysisEvent $event, Codebase $codebase): ?string
    {
        $receiverType = $event->getStatementsSource()->getNodeTypeProvider()->getType($expr->var);

        if (!$receiverType instanceof Union) {
            return null;
        }

        $modelClass = null;

        foreach ($receiverType->getAtomicTypes() as $atomicType) {
            if (!$atomicType instanceof TNamedObject || !self::isModelSubclass($atomicType->value, $codebase)) {
                $modelClass = null;
                break;
            }

            if ($modelClass === null) {
                $modelClass = $atomicType->value;
            } elseif ($modelClass !== $atomicType->value) {
                return null;
            }
        }

        return $modelClass ?? ModelPropertyResolver::resolveExactlyOneModelClass(null, 0, $receiverType, $codebase);
    }

    /**
     * @psalm-assert-if-true class-string<Model> $className
     *
     * @psalm-external-mutation-free
     */
    private static function isModelSubclass(string $className, Codebase $codebase): bool
    {
        if ($className === Model::class) {
            return true;
        }

        if (!$codebase->classExists($className)) {
            return false;
        }

        try {
            return $codebase->classExtends($className, Model::class);
        } catch (\InvalidArgumentException|UnpopulatedClasslikeException) {
            return false;
        }
    }

    /** @psalm-pure */
    private static function shortClassName(string $fqcn): string
    {
        $pos = \strrpos($fqcn, '\\');

        return $pos !== false ? \substr($fqcn, $pos + 1) : $fqcn;
    }
}
