<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Validation;

use PhpParser\Node\Expr;
use PhpParser\Node\Expr\Array_;
use Psalm\CodeLocation;
use Psalm\Plugin\EventHandler\AfterMethodCallAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterMethodCallAnalysisEvent;
use Psalm\StatementsSource;

/**
 * `Validator::make($data, $rules)` checks each value of `$data` against the `regex:` rules of its key: once
 * `$validator->fails()` is false (or `passes()` true), the variable it was given can't carry the taints the pattern
 * removes (see Codebase::addTaintValidation()).
 */
final class ValidatorMakeTaintHandler implements AfterMethodCallAnalysisInterface
{
    private const MAKE_METHODS = [
        'illuminate\\support\\facades\\validator::make',
        'illuminate\\validation\\factory::make',
        'illuminate\\contracts\\validation\\factory::make',
    ];

    #[\Override]
    public static function afterMethodCallAnalysis(AfterMethodCallAnalysisEvent $event): void
    {
        $codebase = $event->getCodebase();
        $expr = $event->getExpr();
        $result = $event->getReturnTypeCandidate();

        if ($result === null
            || !\in_array(\strtolower($event->getMethodId()), self::MAKE_METHODS, true)
            || ($expr instanceof Expr\MethodCall && $expr->isFirstClassCallable())
            || ($expr instanceof Expr\StaticCall && $expr->isFirstClassCallable())
        ) {
            return;
        }

        $args = $expr->getArgs();
        $data = $args[0]->value ?? null;
        $rules = $args[1]->value ?? null;

        if (!$data instanceof Array_ || !$rules instanceof Array_) {
            return;
        }

        $source = $event->getStatementsSource();
        $patterns = self::getPatternsByKey($source, $rules);
        $location = new CodeLocation($source, $expr);

        foreach ($data->items as $item) {
            $key = $item->key === null ? null : self::getLiteral($source, $item->key);

            if ($key === null || !isset($patterns[$key])) {
                continue;
            }

            $removed_taints = 0;
            foreach ($patterns[$key] as $pattern) {
                $removed_taints |= $codebase->getTaintsRemovedByPattern($pattern);
            }

            $result = $codebase->addTaintValidation(
                $source,
                $result,
                $item->value,
                $removed_taints,
                'validator',
                $location,
            );
        }

        $event->setReturnTypeCandidate($result);
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

            foreach ($field_rules as $rule) {
                if (\str_starts_with($rule, 'regex:')) {
                    $patterns[$key][] = \substr($rule, 6);
                }
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
