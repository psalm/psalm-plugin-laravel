<?php

declare(strict_types=1);

/**
 * Run a command with this process's stdio, environment and cwd, then write the
 * peak RSS of its largest process (MB, one decimal) to <rss-file>.
 *
 * The chain is psalm-laravel analyze, then psalm, then Psalm's restart into a
 * child with different ini settings (XdebugHandler proc_open + proc_close), then
 * amphp ForkContext workers (pcntl_fork + pcntl_waitpid). getrusage(RUSAGE_CHILDREN)
 * covers every level only because each parent waits for its child before exiting.
 * It is a maximum over processes, not a sum, and forked workers inherit
 * copy-on-write pages, so the largest process is often a worker. The file is
 * left empty when the figure is unavailable.
 *
 * Usage: php delta-measure.php <rss-file> <command> [args...]
 * Exit code: the command's status as proc_close reports it, 127 when it cannot start.
 */

if (count($argv) < 3) {
    fwrite(STDERR, "Usage: php delta-measure.php <rss-file> <command> [args...]\n");
    exit(2);
}

$rssFile = $argv[1];
file_put_contents($rssFile, '');

$process = @proc_open(array_slice($argv, 2), [STDIN, STDOUT, STDERR], $pipes);
if (!is_resource($process)) {
    fwrite(STDERR, "delta-measure: cannot start {$argv[2]}\n");
    exit(127);
}

$exitCode = proc_close($process);

$usage = getrusage(1);
if (is_array($usage) && isset($usage['ru_maxrss']) && $usage['ru_maxrss'] > 0) {
    // ru_maxrss is bytes on Darwin, kilobytes everywhere else.
    $megabytes = $usage['ru_maxrss'] / (PHP_OS_FAMILY === 'Darwin' ? 1_048_576 : 1_024);
    file_put_contents($rssFile, sprintf('%.1f', $megabytes));
}

exit($exitCode < 0 ? 1 : $exitCode);
