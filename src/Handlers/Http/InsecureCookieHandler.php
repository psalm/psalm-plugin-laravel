<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Http;

use PhpParser\Node\Arg;
use Psalm\CodeLocation;
use Psalm\Issue\InsecureCookie;
use Psalm\IssueBuffer;
use Psalm\Plugin\EventHandler\AfterFunctionCallAnalysisInterface;
use Psalm\Plugin\EventHandler\AfterMethodCallAnalysisInterface;
use Psalm\Plugin\EventHandler\Event\AfterFunctionCallAnalysisEvent;
use Psalm\Plugin\EventHandler\Event\AfterMethodCallAnalysisEvent;
use Psalm\StatementsSource;

use function strtolower;

/**
 * Reports a cookie made with the secure flag explicitly off (`secure: false`), which the browser also sends over plain
 * HTTP. Without the flag Laravel uses `config('session.secure')`, which the analysis can't tell, so only a literal
 * false is reported.
 *
 * @internal
 */
final class InsecureCookieHandler implements AfterFunctionCallAnalysisInterface, AfterMethodCallAnalysisInterface
{
    /**
     * The offset of the `$secure` parameter of the methods making a cookie (declaring method ids, lowercase).
     * ResponseTrait::cookie() and withCookie() pass their arguments on to cookie().
     */
    private const SECURE_PARAM_OFFSETS = [
        'illuminate\cookie\cookiejar::make' => 5,
        'illuminate\cookie\cookiejar::forever' => 4,
        'illuminate\http\responsetrait::cookie' => 5,
        'illuminate\http\responsetrait::withcookie' => 5,
    ];

    #[\Override]
    public static function afterMethodCallAnalysis(AfterMethodCallAnalysisEvent $event): void
    {
        $offset = self::SECURE_PARAM_OFFSETS[strtolower($event->getDeclaringMethodId())] ?? null;

        if ($offset !== null) {
            self::checkSecureFlag($event->getStatementsSource(), $event->getExpr()->getArgs(), $offset);
        }
    }

    #[\Override]
    public static function afterFunctionCallAnalysis(AfterFunctionCallAnalysisEvent $event): void
    {
        if (strtolower($event->getFunctionId()) === 'cookie') {
            self::checkSecureFlag($event->getStatementsSource(), $event->getExpr()->getArgs(), 5);
        }
    }

    /**
     * @param array<array-key, Arg> $args
     */
    private static function checkSecureFlag(StatementsSource $source, array $args, int $offset): void
    {
        $secure = null;

        foreach ($args as $position => $arg) {
            if ($arg->unpack) {
                return;
            }

            if ($arg->name !== null ? $arg->name->name === 'secure' : $position === $offset) {
                $secure = $arg;
            }
        }

        if ($secure === null) {
            return;
        }

        $type = $source->getNodeTypeProvider()->getType($secure->value);

        if ($type === null || !$type->isFalse()) {
            return;
        }

        IssueBuffer::maybeAdd(
            new InsecureCookie(
                'The cookie is made without the secure flag, so it is also sent over plain HTTP',
                new CodeLocation($source, $secure->value),
            ),
            $source->getSuppressedIssues(),
        );
    }
}
