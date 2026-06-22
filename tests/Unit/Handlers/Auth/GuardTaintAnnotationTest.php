<?php

declare(strict_types=1);

namespace Tests\Psalm\LaravelPlugin\Unit\Handlers\Auth;

use Illuminate\Auth\SessionGuard;
use Illuminate\Auth\TokenGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Psalm\LaravelPlugin\Handlers\Auth\GuardTaintHandler;
use Psalm\LaravelPlugin\Handlers\Validation\ValidationRuleAnalyzer;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\MethodStorage;
use Psalm\Type\TaintKind;

/**
 * #1143: the guard taint handler must treat the two guard methods asymmetrically when absent.
 *
 * `SessionGuard::hashPasswordForCookie()` is a Laravel 12+ method (framework #58107), legitimately
 * absent on the supported Laravel 11 line; it carries only a no-op `user_secret` escape, so its
 * absence must stay silent rather than warn about a skipped no-op on every Laravel 11 scan.
 * `TokenGuard::getTokenForRequest()` is a load-bearing taint *source* present on every supported
 * version, so a vanished method must still warn (a missing source is an invisible false negative).
 *
 * Driven through {@see GuardTaintHandler::annotateGuardTaints()} with synthetic storage so the
 * absence path is exercised deterministically regardless of the installed Laravel — where, being a
 * 12+ release, `hashPasswordForCookie()` exists and the regression cannot reproduce.
 */
#[CoversClass(GuardTaintHandler::class)]
final class GuardTaintAnnotationTest extends TestCase
{
    #[Test]
    public function a_missing_session_guard_method_does_not_warn(): void
    {
        $progress = new RecordingProgress();

        GuardTaintHandler::annotateGuardTaints(new ClassLikeStorage(SessionGuard::class), $progress);

        self::assertSame(
            [],
            $progress->warnings,
            'hashPasswordForCookie() is absent on Laravel 11 by design — its no-op escape must stay silent',
        );
    }

    #[Test]
    public function a_missing_token_guard_method_warns(): void
    {
        $progress = new RecordingProgress();

        GuardTaintHandler::annotateGuardTaints(new ClassLikeStorage(TokenGuard::class), $progress);

        self::assertCount(1, $progress->warnings, 'a vanished load-bearing taint source must surface');
        self::assertStringContainsString('gettokenforrequest', $progress->warnings[0]);
    }

    #[Test]
    public function a_non_guard_class_is_a_silent_no_op(): void
    {
        // afterClassLikeVisit fires for every class Psalm visits; only the two guard branches may act.
        // Pins the branch boundary so a loosened if/elseif (e.g. a stray `else`) can't warn on
        // arbitrary classes — the warning channel is otherwise unobserved by any other test.
        $progress = new RecordingProgress();

        GuardTaintHandler::annotateGuardTaints(new ClassLikeStorage(\stdClass::class), $progress);

        self::assertSame([], $progress->warnings);
    }

    #[Test]
    public function a_present_session_guard_method_gets_the_user_secret_escape(): void
    {
        $storage = new ClassLikeStorage(SessionGuard::class);
        $storage->methods['hashpasswordforcookie'] = new MethodStorage();

        GuardTaintHandler::annotateGuardTaints($storage, new RecordingProgress());

        self::assertSame(
            [TaintKind::USER_SECRET],
            $storage->methods['hashpasswordforcookie']->removed_taints,
        );
    }

    #[Test]
    public function a_present_token_guard_method_gets_the_input_taint_source(): void
    {
        $storage = new ClassLikeStorage(TokenGuard::class);
        $storage->methods['gettokenforrequest'] = new MethodStorage();

        GuardTaintHandler::annotateGuardTaints($storage, new RecordingProgress());

        // The source must carry exactly the all-input taint set (the contract: the token is read from
        // request input). Canonicalizing keeps this independent of merge order/dedup.
        self::assertEqualsCanonicalizing(
            ValidationRuleAnalyzer::allInputTaints(),
            $storage->methods['gettokenforrequest']->taint_source_types,
        );
    }
}
