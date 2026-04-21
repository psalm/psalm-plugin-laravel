<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Validation;

use PhpParser\Node\Expr\MethodCall;
use PhpParser\Node\Identifier;
use Psalm\Internal\Analyzer\StatementsAnalyzer;
use Psalm\Plugin\EventHandler\AddTaintsInterface;
use Psalm\Plugin\EventHandler\Event\AddRemoveTaintsEvent;
use Psalm\Plugin\EventHandler\RemoveTaintsInterface;
use Psalm\Type\Atomic\TGenericObject;
use Psalm\Type\Atomic\TNamedObject;
use Psalm\Type\TaintKind;
use Psalm\Type\Union;

/**
 * Manages taint for validated data read from FormRequest, Request, and ValidatedInput.
 *
 * Two responsibilities, split across the two interfaces:
 *
 * 1. Add taint where {@see ValidatedTypeHandler} narrows the return type.
 *    A type-provider override causes Psalm to drop the stub's
 *    @psalm-taint-source annotation, so we must re-introduce it.
 *    Covers: FormRequest::validated()/safe()/validate(),
 *            ValidatedInput::input('key').
 *
 * 2. Remove taint per field when the declared validation rule constrains the
 *    value in a way that makes it safe for a specific sink family
 *    (e.g. 'email' rule → safe for 'header' and 'cookie').
 *    Covers keyed accessors that read from the same data pool as validation:
 *            FormRequest::validated/input/string/str('key'),
 *            ValidatedInput::input/string/str('key').
 *
 * Design assumption: when a typed FormRequest is injected into a controller,
 * Laravel runs validation before the controller method executes (via
 * ValidatesWhenResolvedTrait). So any input/string/str read from that
 * FormRequest carries a value that already passed rules() — the rule's taint
 * escape applies even when the caller uses input() instead of validated().
 *
 * Caveat: the escape on input()/string()/str() assumes validation has run
 * against the same data pool these accessors read. That assumption can break
 * in a few (rare) scenarios:
 *   - a subclass's passedValidation() calls $this->merge(...) with raw content
 *     on a rule-covered key;
 *   - a subclass overrides validationData() to validate a different source
 *     (e.g. $this->json()->all()) than input() reads;
 *   - input() is called before validation runs (e.g. inside prepareForValidation,
 *     rules(), or authorize()) — the static analyzer cannot see call ordering;
 *   - precognition mode strips rules from the live validator while the static
 *     rules() still parses the full set.
 * In all of these, validated() and safe()->input() still reflect the validated
 * snapshot. Prefer them in security-sensitive paths.
 *
 * NOT handled here (deliberate):
 *   - query(), post(), json(), cookie(), server(), header(), file():
 *     these read from a specific transport rather than the validated merge,
 *     so a rule on 'team_email' does not necessarily describe $req->query('team_email').
 *   - integer/float/boolean/date/enum:
 *     cast methods are not taint sources (see InteractsWithInput.stubphp).
 *
 * Upstream workaround for Psalm dropping the stub source on override:
 *   https://github.com/vimeo/psalm/issues/11765
 *
 * Architecture follows {@see \Psalm\Internal\Provider\AddRemoveTaints\HtmlFunctionTainter}.
 *
 * Performance: fires on every expression. The bail-out chain rejects non-matching
 * expressions fast (instanceof, then method name, then caller-class check).
 */
final class ValidationTaintHandler implements AddTaintsInterface, RemoveTaintsInterface
{
    /**
     * Accessor methods whose single-key form selects a rule-covered field.
     *
     * Listed explicitly (not derived) so reviewers can audit the set.
     */
    private const KEYED_ACCESSOR_METHODS = ['validated', 'input', 'string', 'str'];

    /**
     * Add taint to validation method calls whose return type we narrow.
     *
     * Without this, the override in {@see ValidatedTypeHandler} would cause
     * Psalm to silently drop the stub's @psalm-taint-source annotation,
     * producing false negatives on sinks that consume the narrowed value.
     */
    #[\Override]
    public static function addTaints(AddRemoveTaintsEvent $event): int
    {
        if (self::isValidationMethodCall($event)) {
            return TaintKind::ALL_INPUT;
        }

        // ValidatedInput::input('key') also has its return type narrowed
        // (see ValidatedTypeHandler::resolveValidatedInputMethod), so the
        // stub source is dropped there as well.
        if (self::isValidatedInputAccessor($event)) {
            return TaintKind::ALL_INPUT;
        }

        return 0;
    }

    /**
     * Remove taint kinds that the declared rule guarantees cannot occur in
     * the validated value. Applies to all keyed accessors in
     * KEYED_ACCESSOR_METHODS whose caller resolves to either a FormRequest
     * subclass or ValidatedInput<FormRequest>.
     */
    #[\Override]
    public static function removeTaints(AddRemoveTaintsEvent $event): int
    {
        $match = self::matchKeyedAccess($event);

        if ($match === null) {
            return 0;
        }

        $rules = ValidationRuleAnalyzer::getRulesForFormRequest($match['class']);

        if ($rules === null) {
            return 0;
        }

        return $rules[$match['key']]->removedTaints ?? 0;
    }

    /**
     * Match a keyed accessor call and resolve the backing FormRequest class,
     * whether the call is on the FormRequest itself or on ValidatedInput<FormRequest>.
     *
     * @return array{class: class-string, key: string}|null
     */
    private static function matchKeyedAccess(AddRemoveTaintsEvent $event): ?array
    {
        $expr = $event->getExpr();

        if (!$expr instanceof MethodCall || !$expr->name instanceof Identifier) {
            return null;
        }

        $methodName = $expr->name->toLowerString();

        if (!\in_array($methodName, self::KEYED_ACCESSOR_METHODS, true)) {
            return null;
        }

        $args = $expr->getArgs();

        if ($args === []) {
            return null;
        }

        $statementsAnalyzer = $event->getStatementsSource();

        if (!$statementsAnalyzer instanceof StatementsAnalyzer) {
            return null;
        }

        $firstArgType = $statementsAnalyzer->node_data->getType($args[0]->value);

        if (!$firstArgType instanceof Union || !$firstArgType->isSingleStringLiteral()) {
            return null;
        }

        $fieldKey = $firstArgType->getSingleStringLiteral()->value;

        // Direct FormRequest caller: $req->validated|input|string|str('key')
        $formRequestClass = self::resolveCallerClass($event, \Illuminate\Foundation\Http\FormRequest::class);

        if ($formRequestClass !== null) {
            return ['class' => $formRequestClass, 'key' => $fieldKey];
        }

        // ValidatedInput<FormRequest> caller: $req->safe()->input|string|str('key').
        // validated() does not exist on ValidatedInput, so this branch applies
        // only to input/string/str.
        if ($methodName !== 'validated') {
            $formRequestClass = self::extractFormRequestFromValidatedInput($event);

            if ($formRequestClass !== null) {
                return ['class' => $formRequestClass, 'key' => $fieldKey];
            }
        }

        return null;
    }

    /**
     * Whether the expression is validated()/validate()/safe() on Request/FormRequest.
     */
    private static function isValidationMethodCall(AddRemoveTaintsEvent $event): bool
    {
        $expr = $event->getExpr();

        if (!$expr instanceof MethodCall || !$expr->name instanceof Identifier) {
            return false;
        }

        $methodName = $expr->name->toLowerString();

        if (!\in_array($methodName, ['validated', 'validate', 'safe'], true)) {
            return false;
        }

        // validated() and safe() are FormRequest methods, validate() is on Request
        $baseClass = ($methodName === 'validated' || $methodName === 'safe')
            ? \Illuminate\Foundation\Http\FormRequest::class
            : \Illuminate\Http\Request::class;

        return self::resolveCallerClass($event, $baseClass) !== null;
    }

    /**
     * Check for ValidatedInput::input(…) — any first argument, literal or not.
     *
     * addTaints compensates for the type-provider override; the per-field
     * rule lookup in removeTaints additionally requires a literal key.
     */
    private static function isValidatedInputAccessor(AddRemoveTaintsEvent $event): bool
    {
        $expr = $event->getExpr();

        if (!$expr instanceof MethodCall || !$expr->name instanceof Identifier) {
            return false;
        }

        if ($expr->name->toLowerString() !== 'input') {
            return false;
        }

        if ($expr->getArgs() === []) {
            return false;
        }

        return self::extractFormRequestFromValidatedInput($event) !== null;
    }

    /**
     * Extract the FormRequest class from a ValidatedInput<FormRequest> caller type.
     *
     * The template parameter is populated when FormRequest::safe() returns
     * ValidatedInput<static> — so every safe() on a typed FormRequest is resolvable.
     *
     * @return class-string|null
     */
    private static function extractFormRequestFromValidatedInput(AddRemoveTaintsEvent $event): ?string
    {
        $expr = $event->getExpr();

        if (!$expr instanceof MethodCall) {
            return null;
        }

        $statementsAnalyzer = $event->getStatementsSource();

        if (!$statementsAnalyzer instanceof StatementsAnalyzer) {
            return null;
        }

        $callerType = $statementsAnalyzer->node_data->getType($expr->var);

        if (!$callerType instanceof Union) {
            return null;
        }

        $codebase = $event->getCodebase();

        foreach ($callerType->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TGenericObject) {
                continue;
            }

            if ($atomic->value !== \Illuminate\Support\ValidatedInput::class) {
                continue;
            }

            if (!isset($atomic->type_params[0])) {
                continue;
            }

            foreach ($atomic->type_params[0]->getAtomicTypes() as $paramAtomic) {
                if (!$paramAtomic instanceof TNamedObject) {
                    continue;
                }

                /** @var class-string $className */
                $className = $paramAtomic->value;

                try {
                    if ($className === \Illuminate\Foundation\Http\FormRequest::class
                        || $codebase->classExtends($className, \Illuminate\Foundation\Http\FormRequest::class)
                    ) {
                        return $className;
                    }
                } catch (\Psalm\Exception\UnpopulatedClasslikeException|\InvalidArgumentException) {
                    continue;
                }
            }
        }

        return null;
    }

    /**
     * Resolve a class from the method call's caller type that matches or extends the given base class.
     *
     * Shared by addTaints (via isValidationMethodCall) and matchKeyedAccess
     * to avoid duplicating the classExtends resolution logic.
     *
     * @param class-string $baseClass
     * @return class-string|null
     */
    private static function resolveCallerClass(
        AddRemoveTaintsEvent $event,
        string $baseClass,
    ): ?string {
        $expr = $event->getExpr();

        if (!$expr instanceof MethodCall) {
            return null;
        }

        $statementsAnalyzer = $event->getStatementsSource();

        if (!$statementsAnalyzer instanceof StatementsAnalyzer) {
            return null;
        }

        $callerType = $statementsAnalyzer->node_data->getType($expr->var);

        if (!$callerType instanceof Union) {
            return null;
        }

        $codebase = $event->getCodebase();

        foreach ($callerType->getAtomicTypes() as $atomic) {
            if (!$atomic instanceof TNamedObject) {
                continue;
            }

            /** @var class-string $className */
            $className = $atomic->value;

            try {
                if ($className === $baseClass || $codebase->classExtends($className, $baseClass)) {
                    return $className;
                }
            } catch (\Psalm\Exception\UnpopulatedClasslikeException|\InvalidArgumentException) {
                continue;
            }
        }

        return null;
    }
}
