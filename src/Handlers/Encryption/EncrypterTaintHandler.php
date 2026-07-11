<?php

declare(strict_types=1);

namespace Psalm\LaravelPlugin\Handlers\Encryption;

use Illuminate\Encryption\Encrypter;
use Psalm\LaravelPlugin\Internal\WarningReporter;
use Psalm\Plugin\EventHandler\AfterClassLikeVisitInterface;
use Psalm\Plugin\EventHandler\Event\AfterClassLikeVisitEvent;
use Psalm\Storage\ClassLikeStorage;
use Psalm\Storage\MethodStorage;
use Psalm\Type\TaintKind;

/**
 * Attaches the encrypter's taint metadata to the *real* {@see Encrypter} class, instead of via a
 * full-class `.phpstub`.
 *
 * A stub that redeclares `Illuminate\Encryption\Encrypter` to host its `@psalm-taint-*` methods
 * shadows the whole class: a class declaration in a stub claims the class's file slot, so when user
 * code reaches the encrypter only through container narrowing — `app('encrypter')` /
 * `resolve('encrypter')` / `make('encrypter')`, which the plugin's {@see \Psalm\LaravelPlugin\Handlers\Application\ContainerResolver}
 * resolves to a bare `Encrypter` object *without naming the class* — Psalm never scans the real
 * vendor source and strips every other method, breaking `app('encrypter')->getKey()` and friends
 * with `UndefinedMethod`. This is the same trap fixed for the auth guards in #1113; unlike most
 * Laravel service classes (`Repository`, `Store`, `Connection`, …) the encrypter carries no
 * `Macroable`/`__call` to mask the stripped methods, so the shadow surfaces as a hard error.
 *
 * Instead we set the same fields Psalm derives from a `@psalm-taint-escape` / `@psalm-taint-unescape`
 * / `@psalm-flow` docblock — `removed_taints` / `added_taints` / `return_source_params` — directly on
 * the real method storage during the scan phase (exactly what `FunctionLikeDocblockScanner` does for
 * those tags). The instance-call taint path reads them back: `MethodCallReturnTypeFetcher` delegates
 * to `FunctionCallReturnTypeFetcher::taintUsingStorage()` (which turns `added_taints` into a return
 * taint source, masked by `removed_taints`) and `taintUsingFlows()` (which walks `return_source_params`
 * and propagates each argument's taints to the return along its flow, adding `added_taints` and
 * stripping `removed_taints` on that edge). The real class is left fully intact, so its methods
 * resolve normally.
 *
 * What this reproduces from the deleted stub (verbatim — the security contract must not drift):
 *
 *  - {@see Encrypter::encrypt()} / {@see Encrypter::encryptString()} turn a value into ciphertext, so
 *    a `user_secret` / `system_secret` taint is *escaped* from the result (was `@psalm-taint-escape
 *    user_secret` + `system_secret`). The `@psalm-flow ($value) -> return` is preserved so any
 *    *other* taint kind still propagates through the call unchanged.
 *  - {@see Encrypter::decrypt()} / {@see Encrypter::decryptString()} turn ciphertext back into
 *    plaintext, so those same secret taints are *restored* (was `@psalm-taint-unescape user_secret` +
 *    `system_secret`), again flowing the payload through to the return.
 */
final class EncrypterTaintHandler implements AfterClassLikeVisitInterface
{
    /**
     * Methods that escape secret taint as a value is encrypted (`@psalm-taint-escape`).
     *
     * @var list<lowercase-string>
     */
    private const ESCAPERS = ['encrypt', 'encryptstring'];

    /**
     * Methods that restore secret taint as a value is decrypted (`@psalm-taint-unescape`).
     *
     * @var list<lowercase-string>
     */
    private const UNESCAPERS = ['decrypt', 'decryptstring'];

    #[\Override]
    public static function afterClassLikeVisit(AfterClassLikeVisitEvent $event): void
    {
        $storage = $event->getStorage();

        if (\strcasecmp($storage->name, Encrypter::class) !== 0) {
            return;
        }

        foreach (self::ESCAPERS as $method_name) {
            $method = self::resolveMethod($event, $storage, $method_name);

            if ($method instanceof MethodStorage) {
                $method->removed_taints |= TaintKind::USER_SECRET | TaintKind::SYSTEM_SECRET;
                self::flowFirstParamToReturn($method);
            }
        }

        foreach (self::UNESCAPERS as $method_name) {
            $method = self::resolveMethod($event, $storage, $method_name);

            if ($method instanceof MethodStorage) {
                $method->added_taints |= TaintKind::USER_SECRET | TaintKind::SYSTEM_SECRET;
                self::flowFirstParamToReturn($method);
            }
        }
    }

    /**
     * Reproduce `@psalm-flow ($value) -> return`: the value/payload is the first parameter on all
     * four methods (verified against Laravel's `encrypt`/`encryptString`/`decrypt`/`decryptString`
     * signatures), and a plain (non-`-(kind)->`) flow uses the `'arg'` path type — exactly what
     * `FunctionLikeDocblockScanner::handleTaintFlow()` records. The deleted stub matched the flow
     * source by name (`($value)`/`($payload)`); index 0 is equivalent for these signatures and would
     * need revisiting if Laravel ever made the value/payload not first.
     */
    private static function flowFirstParamToReturn(MethodStorage $method): void
    {
        if ($method->params !== []) {
            $method->return_source_params[0] = 'arg';
        }
    }

    /**
     * Look up a method on the (already-matched) encrypter class, warning if it is absent.
     *
     * A taint method silently vanishing is a security regression — a missing escape is an invisible
     * false negative — so we surface it the way {@see \Psalm\LaravelPlugin\Handlers\Auth\GuardTaintHandler}
     * does: a `warning` (debug is a no-op under the default progress). It only fires for the matched
     * class, so there is no per-class-visit spam.
     *
     * @param lowercase-string $method
     */
    private static function resolveMethod(AfterClassLikeVisitEvent $event, ClassLikeStorage $storage, string $method): ?MethodStorage
    {
        $method_storage = $storage->methods[$method] ?? null;

        if ($method_storage === null) {
            WarningReporter::emit(
                $event->getCodebase()->progress,
                "Laravel plugin: {$storage->name}::{$method}() not found — encrypter taint annotation skipped. "
                . 'The Laravel method signature may have changed; please report this against psalm-plugin-laravel.',
            );
        }

        return $method_storage;
    }
}
