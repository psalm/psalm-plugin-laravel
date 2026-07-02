--ARGS--
--no-progress --no-diff --config=./tests/Type/psalm-with-empty-experimental.xml
--FILE--
<?php declare(strict_types=1);

/**
 * Regression guard for #1203: a childless <experimental /> element must degrade to a
 * deprecation-style notice, not crash the whole analysis run.
 *
 * PluginConfig::xmlExperimentalFeatures() used to raise this notice via
 * trigger_error(E_USER_DEPRECATED). Psalm's own CLI installs an error handler
 * (Psalm\Internal\ErrorHandler::install()) before plugin loading that turns every PHP
 * error/warning/deprecation into a thrown RuntimeException, which
 * Psalm\Config::initializePlugins() then re-wraps as a fatal ConfigException — aborting
 * analysis entirely instead of emitting a soft notice. Unit tests never caught this: PHPUnit's
 * own deprecation handling is a different mechanism from Psalm\Internal\ErrorHandler, which
 * is only installed inside a real `vendor/bin/psalm` process — exactly what this .phpt runs
 * through (unlike a plain PHPUnit test).
 *
 * The notice is now collected into PluginConfig::$experimentalNotices and surfaced via
 * Psalm\Progress\Progress::warning() from Plugin::reportActiveExperiments() instead.
 *
 * @see https://github.com/psalm/psalm-plugin-laravel/issues/1203
 */
function test_trivial_function_still_analyzes(int $x): int
{
    return $x + 1;
}
?>
--EXPECTF--
