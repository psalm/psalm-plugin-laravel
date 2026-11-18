<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Validation;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Identifier;
use PhpParser\Node\Name;
use Psalm\Plugin\EventHandler\AfterExpressionAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterExpressionAnalysisEvent;
use Psalm\StatementsSource;
use Psalm\Type\Atomic\TNamedObject;

/**
 * `Validator::make($data, $rules)` (`$factory->make()`, `validator($data, $rules)`) checks each value of `$data`
 * against the `regex:` rules of its key: once `$validator->fails()` is false (or `passes()` true), the variable it was
 * given can't carry the taints the pattern removes (see Codebase::addTaintValidation()). A validator whose rules or
 * data are changed after it is made (`setRules()`, `setData()`) isn't told apart.
 */
final class ValidatorMakeTaintHandler implements AfterExpressionAnalysisInterface
{
    #[\Override]
    public static function afterExpressionAnalysis(AfterExpressionAnalysisEvent $event): ?bool
    {
        $expr = $event->getExpr();
        $source = $event->getStatementsSource();

        if (!self::makesAValidator($source, $expr)) {
            return null;
        }

        $args = $expr->getArgs();
        $data = $args[0]->value ?? null;
        $rules = $args[1]->value ?? null;

        if (!$data instanceof Array_ || !$rules instanceof Array_) {
            return null;
        }

        $patterns = self::getPatternsByKey($source, $rules);

        // the value each key ends up with: the last item given it, unless a spread or a key that can't be told can
        // override it
        $values = [];
        foreach ($data->items as $item) {
            $key = $item->key === null || $item->unpack ? null : self::getLiteral($source, $item->key);

            if ($key === null) {
                return null;
            }

            $values[$key] = $item->value;
        }

        $codebase = $event->getCodebase();
        foreach ($values as $key => $value) {
            if (!isset($patterns[$key])) {
                continue;
            }

            $removed_taints = 0;
            foreach ($patterns[$key] as $pattern) {
                $removed_taints |= $codebase->getTaintsRemovedByPattern($pattern);
            }

            $codebase->addTaintValidation($source, $expr, $value, $removed_taints, 'validator');
        }

        return null;
    }

    /**
     * @psalm-assert-if-true Expr\StaticCall|Expr\MethodCall|Expr\FuncCall $expr
     */
    private static function makesAValidator(StatementsSource $source, Expr $expr): bool
    {
        if ($expr instanceof Expr\FuncCall) {
            if (!$expr->name instanceof Name || \strtolower($expr->name->toString()) !== 'validator') {
                return false;
            }
        } elseif ($expr instanceof Expr\StaticCall || $expr instanceof Expr\MethodCall) {
            if (!$expr->name instanceof Identifier || $expr->name->toLowerString() !== 'make') {
                return false;
            }
        } else {
            return false;
        }

        if ($expr->isFirstClassCallable()) {
            return false;
        }

        $type = $source->getNodeTypeProvider()->getType($expr);
        $atomic = $type?->isSingle() ? $type->getSingleAtomic() : null;

        return $atomic instanceof TNamedObject
            && \in_array(
                $atomic->value,
                [\Illuminate\Validation\Validator::class, \Illuminate\Contracts\Validation\Validator::class],
                true,
            );
    }

    /**
     * The patterns of the `regex:` rules of each key
     *
     * @return array<string, list<string>>
     */
    private static function getPatternsByKey(StatementsSource $source, Array_ $rules): array
    {
        $patterns = [];

        foreach ($rules->items as $item) {
            $key = $item->key === null ? null : self::getLiteral($source, $item->key);

            if ($key === null) {
                continue;
            }

            $field_rules = [];
            if ($item->value instanceof Array_) {
                foreach ($item->value->items as $rule) {
                    $literal = self::getLiteral($source, $rule->value);
                    if ($literal !== null) {
                        $field_rules[] = $literal;
                    }
                }
            } else {
                // a pattern holding `|` must be given in an array
                $literal = self::getLiteral($source, $item->value);
                $field_rules = $literal === null ? [] : \explode('|', $literal);
            }

            // a nested or wildcard attribute (`a.b`, `a.*`) isn't the value under the key, and a field validated only
            // sometimes may not be
            if (str_contains($key, '.') || str_contains($key, '*')) {
                continue;
            }

            $field_patterns = [];
            foreach ($field_rules as $rule) {
                if (str_starts_with($rule, 'exclude') || str_starts_with($rule, 'sometimes')) {
                    continue 2;
                }

                if (str_starts_with($rule, 'regex:')) {
                    $field_patterns[] = substr($rule, 6);
                }
            }

            if ($field_patterns !== []) {
                $patterns[$key] = $field_patterns;
            }
        }

        return $patterns;
    }

    private static function getLiteral(StatementsSource $source, Expr $expr): ?string
    {
        $type = $source->getNodeTypeProvider()->getType($expr);

        if ($type === null || !$type->isSingleStringLiteral()) {
            return null;
        }

        return $type->getSingleStringLiteral()->value;
    }
}
