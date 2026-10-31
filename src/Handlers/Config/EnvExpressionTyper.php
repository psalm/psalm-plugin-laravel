<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Config;

use PhpParser\ConstExprEvaluationException;
use PhpParser\ConstExprEvaluator;
use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;
use PhpParser\NodeFinder;
use Psalm\LaravelPlugin\Handlers\Helpers\EnvHandler;
use Psalm\Type;
use Psalm\Type\Atomic\TBool;
use Psalm\Type\Atomic\TFalse;
use Psalm\Type\Atomic\TFloat;
use Psalm\Type\Atomic\TInt;
use Psalm\Type\Atomic\TKeyedArray;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\Atomic\TNull;
use Psalm\Type\Atomic\TString;
use Psalm\Type\Atomic\TTrue;
use Psalm\Type\Union;

/**
 * Types a config-file expression that reads the environment from its syntax alone, so the
 * result never depends on the env of the machine running Psalm. Expects name-resolved nodes
 * from {@see ConfigSourceIndex}.
 *
 * @internal
 */
final class EnvExpressionTyper
{
    /** Binary operators that always evaluate to a bool (`<=>` is an int). */
    private const BOOL_OPERATORS = ['&&', '||', 'and', 'or', 'xor', '===', '!==', '==', '!=', '<', '<=', '>', '>='];

    /** Laravel helpers that always return a string. */
    private const PATH_HELPERS = ['app_path', 'base_path', 'config_path', 'database_path', 'lang_path', 'public_path', 'resource_path', 'storage_path'];

    /** Marks a variable that may hold an env value, set by {@see ConfigSourceIndex}. */
    public const MAY_HOLD_ENV = 'psalmLaravelMayHoldEnv';

    /**
     * Null when $expr calls something that may or may not read the environment. A stored
     * closure is a value, so its body never runs at config load. Any other nested closure
     * might be invoked (`value(fn () => env('X'))`), so it is searched.
     */
    public static function readsEnv(Expr $expr): ?bool
    {
        if ($expr instanceof Expr\Closure || $expr instanceof Expr\ArrowFunction) {
            return false;
        }

        $finder = new NodeFinder();

        return $finder->findFirst($expr, self::isAmbiguous(...)) instanceof Node
            ? null
            : $finder->findFirst($expr, self::isEnvRead(...)) instanceof Node;
    }

    public static function mayReadEnv(Node $node): bool
    {
        return self::isEnvRead($node) || self::isAmbiguous($node);
    }

    /** `mixed` for any shape the rules do not model. A set variable reads as {@see EnvHandler} types it. */
    public static function type(Expr $expr): Union
    {
        return self::typeOf($expr, false) ?? Type::getMixed();
    }

    /** Every value $expr can evaluate to: wider than {@see type()}, which reads a set variable as a string. */
    public static function producible(Expr $expr): Union
    {
        return self::typeOf($expr, true) ?? Type::getMixed();
    }

    private static function typeOf(Expr $expr, bool $producible): ?Union
    {
        if (self::isEnvGet($expr)) {
            /** @var Expr\FuncCall|Expr\StaticCall $expr */
            return self::typeEnvGet($expr, $producible);
        }

        return match (true) {
            $expr instanceof Expr\FuncCall && !$expr->isFirstClassCallable() => match (true) {
                self::isGlobalCall($expr, 'explode') => Type::getList(Type::getString()),
                self::isGlobalCall($expr, 'getenv') && $expr->getArgs() !== [],
                self::isGlobalCall($expr, 'realpath') => new Union([new TString(), new TFalse()]),
                self::isGlobalCall($expr, ...self::PATH_HELPERS) => Type::getString(),
                default => null,
            },
            $expr instanceof Expr\BinaryOp\Plus,
            $expr instanceof Expr\BinaryOp\Minus,
            $expr instanceof Expr\BinaryOp\Mul => self::evaluated($expr)
                ?? self::arithmetic(self::typeOf($expr->left, $producible), self::typeOf($expr->right, $producible)),
            $expr instanceof Node\Scalar\String_,
            $expr instanceof Node\Scalar\InterpolatedString,
            $expr instanceof Expr\BinaryOp\Concat,
            $expr instanceof Expr\Cast\String_,
            $expr instanceof Expr\ClassConstFetch && $expr->name instanceof Node\Identifier
                && $expr->name->toLowerString() === 'class' => Type::getString(),
            $expr instanceof Node\Scalar\Int_, $expr instanceof Expr\Cast\Int_ => Type::getInt(),
            $expr instanceof Node\Scalar\Float_, $expr instanceof Expr\Cast\Double => Type::getFloat(),
            $expr instanceof Expr\UnaryMinus, $expr instanceof Expr\UnaryPlus => $expr->expr instanceof Node\Scalar\Int_
                || $expr->expr instanceof Node\Scalar\Float_ ? self::typeOf($expr->expr, $producible) : null,
            $expr instanceof Expr\Cast\Bool_,
            $expr instanceof Expr\BooleanNot,
            $expr instanceof Expr\Isset_,
            $expr instanceof Expr\Empty_,
            $expr instanceof Expr\Instanceof_,
            $expr instanceof Expr\BinaryOp && \in_array($expr->getOperatorSigil(), self::BOOL_OPERATORS, true) => Type::getBool(),
            $expr instanceof Expr\Cast\Array_ => Type::getArray(),
            $expr instanceof Expr\Closure, $expr instanceof Expr\ArrowFunction => new Union([new TNamedObject(\Closure::class)]),
            $expr instanceof Expr\Array_ => self::arrayShape($expr, $producible),
            $expr instanceof Expr\ConstFetch => match ($expr->name->toLowerString()) {
                'true', 'false' => Type::getBool(),
                'null' => Type::getNull(),
                default => self::evaluated($expr),
            },
            // `a ?: b` returns a only when a is truthy, `a ?? b` only when a is not null.
            $expr instanceof Expr\Ternary => self::union(
                $expr->if instanceof Expr
                    ? self::typeOf($expr->if, $producible)
                    : self::withoutFalsy(self::typeOf($expr->cond, $producible), true),
                self::typeOf($expr->else, $producible),
            ),
            $expr instanceof Expr\BinaryOp\Coalesce => self::union(
                self::withoutFalsy(self::typeOf($expr->left, $producible), false),
                self::typeOf($expr->right, $producible),
            ),
            default => null,
        };
    }

    /** `env('K', $default)`, `Env::get('K', $default)` and `Env::getOrFail('K')`. */
    private static function typeEnvGet(Expr\FuncCall|Expr\StaticCall $call, bool $producible): ?Union
    {
        // A first-class callable `env(...)` holds a placeholder; named and unpacked arguments are not modelled.
        foreach ($call->args as $arg) {
            if (!$arg instanceof Node\Arg || $arg->name instanceof Node\Identifier || $arg->unpack) {
                return null;
            }
        }

        $args = $call->getArgs();

        // Env::getOrFail() has no default: it throws instead.
        if (!isset($args[1])) {
            return $producible ? self::setVariable() : EnvHandler::returnTypeForDefault(null);
        }

        // Env::get() runs the default through value(), which calls a closure once: an arrow fn
        // contributes its result, a closure with statements is not typed. typeOf() yields no
        // literals, so the default is already generalized.
        $default = $args[1]->value instanceof Expr\ArrowFunction ? $args[1]->value->expr : $args[1]->value;
        $defaultType = $default instanceof Expr\Closure ? null : self::typeOf($default, $producible);

        if (!$defaultType instanceof Union) {
            return null;
        }

        return $producible ? Type::combineUnionTypes(self::setVariable(), $defaultType) : EnvHandler::returnTypeForDefault($defaultType);
    }

    /**
     * Integer arithmetic PHP cannot evaluate here may overflow to a float.
     *
     * @psalm-external-mutation-free
     */
    private static function arithmetic(?Union $left, ?Union $right): ?Union
    {
        $type = self::union($left, $right);

        foreach ($type?->getAtomicTypes() ?? [] as $atomic) {
            if (!$atomic instanceof TInt && !$atomic instanceof TFloat) {
                return null;
            }
        }

        return $type?->hasInt() ? Type::combineUnionTypes($type, Type::getFloat()) : $type;
    }

    private static function arrayShape(Expr\Array_ $array, bool $producible): ?Union
    {
        $items = ConfigSourceIndex::literalItems($array);

        if ($items === null || $items === []) {
            return $items === null ? null : Type::getEmptyArray();
        }

        $properties = [];
        foreach ($items as $key => $item) {
            $type = self::typeOf($item, $producible);

            if (!$type instanceof Union) {
                return null;
            }

            $properties[$key] = $type;
        }

        return new Union([TKeyedArray::make($properties, is_list: \array_is_list($properties))]);
    }

    /**
     * The value PHP computes from literals and PHP or extension constants (`LOG_USER`): an app
     * constant may come from env. NameResolver leaves a constant unqualified only in a namespace,
     * where a namespaced one may win.
     */
    private static function evaluated(Expr $expr): ?Union
    {
        $evaluator = new ConstExprEvaluator(static function (Expr $expr): mixed {
            $name = $expr instanceof Expr\ConstFetch && $expr->name->isFullyQualified() ? $expr->name->toString() : '';

            if (!\defined($name) || \array_key_exists($name, \get_defined_constants(true)['user'] ?? [])) {
                throw new ConstExprEvaluationException();
            }

            return \constant($name);
        });

        // Silently: a deprecated constant or a non-numeric operand raises an error, which
        // Psalm's error handler would rethrow out of the whole config key.
        try {
            $type = ConfigValueReflector::reflect($evaluator->evaluateSilently($expr));
        } catch (ConstExprEvaluationException) {
            return null;
        }

        return $type->hasMixed() ? null : $type;
    }

    /**
     * What a set variable can read as: `Env::get()` maps "true", "false" and "null" to bool and null.
     *
     * @psalm-pure
     */
    private static function setVariable(): Union
    {
        return new Union([new TBool(), new TNull(), new TString()]);
    }

    private static function isEnvGet(Expr $expr): bool
    {
        if ($expr instanceof Expr\FuncCall) {
            return self::isGlobalCall($expr, 'env');
        }

        return $expr instanceof Expr\StaticCall
            && self::isEnvClass($expr->class)
            && $expr->name instanceof Node\Identifier
            && \in_array($expr->name->toLowerString(), ['get', 'getorfail'], true);
    }

    /**
     * A first-class callable builds a closure without reading anything; a dynamic call may be `env`.
     * An invoked closure is no such call: the search reaches its body.
     */
    private static function isEnvRead(Node $node): bool
    {
        return match (true) {
            $node instanceof Expr\Variable => \in_array($node->name, ['_ENV', '_SERVER'], true) || $node->getAttribute(self::MAY_HOLD_ENV) === true,
            $node instanceof Expr\CallLike && $node->isFirstClassCallable() => false,
            $node instanceof Expr\FuncCall => !($node->name instanceof Name || $node->name instanceof Expr\Closure || $node->name instanceof Expr\ArrowFunction)
                || self::isGlobalCall($node, 'env', 'getenv'),
            $node instanceof Expr\StaticCall => !$node->class instanceof Name || self::isEnvClass($node->class),
            default => false,
        };
    }

    /**
     * An unqualified call in a namespace runs a namespaced function of that name if any file
     * declared one before (none ran under config:cache). A class named Env may alias Laravel's.
     * An included file may read env and assign the including scope's variables.
     */
    private static function isAmbiguous(Node $node): bool
    {
        return match (true) {
            $node instanceof Expr\Include_ => true,
            $node instanceof Expr\CallLike && $node->isFirstClassCallable() => false,
            $node instanceof Expr\FuncCall => $node->name instanceof Name && !$node->name->isFullyQualified()
                && \in_array($node->name->toLowerString(), ['env', 'getenv'], true),
            $node instanceof Expr\StaticCall => $node->class instanceof Name && !self::isEnvClass($node->class)
                && \strtolower($node->class->getLast()) === 'env',
            default => false,
        };
    }

    /** NameResolver fully qualifies every function name but an unqualified one in a namespace. */
    private static function isGlobalCall(Expr\FuncCall $call, string ...$functions): bool
    {
        return $call->name instanceof Name && $call->name->isFullyQualified() && \in_array($call->name->toLowerString(), $functions, true);
    }

    private static function isEnvClass(Node $class): bool
    {
        return $class instanceof Name && $class->toLowerString() === 'illuminate\support\env';
    }

    /** @psalm-external-mutation-free */
    private static function union(?Union $a, ?Union $b): ?Union
    {
        return $a instanceof Union && $b instanceof Union ? Type::combineUnionTypes($a, $b) : null;
    }

    /**
     * Drops null, and false too when $dropFalse (bool narrows to true). Never when nothing is left.
     *
     * @psalm-pure
     */
    private static function withoutFalsy(?Union $type, bool $dropFalse): ?Union
    {
        if (!$type instanceof Union) {
            return null;
        }

        $kept = [];
        foreach ($type->getAtomicTypes() as $atomic) {
            if ($atomic instanceof TNull || ($dropFalse && $atomic instanceof TFalse)) {
                continue;
            }

            $kept[] = $dropFalse && $atomic instanceof TBool ? new TTrue() : $atomic;
        }

        return $kept === [] ? Type::getNever() : new Union($kept);
    }
}
