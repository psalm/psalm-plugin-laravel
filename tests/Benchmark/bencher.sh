#!/usr/bin/env bash
# Bencher Bare Metal job (see tests/Benchmark/Dockerfile): times Psalm on the baked-in Monica
# and writes Bencher Metric Format JSON (latency in ns, peak memory in MB) to $1.
set -euo pipefail

: > /tmp/mem.txt
hyperfine --warmup 1 --runs 3 --ignore-failure --style basic --export-json /tmp/hyperfine.json \
    'bash /plugin/tests/Benchmark/run-psalm.sh /monica psalm-benchmark.xml /tmp/mem.txt'

# shellcheck disable=SC2016
php -r '
$h = json_decode(file_get_contents("/tmp/hyperfine.json"), true, 512, JSON_THROW_ON_ERROR)["results"][0];
foreach ($h["exit_codes"] as $code) {
    // 2 is Psalm reporting issues; 1 and signals are crashes.
    if ($code === 1 || $code >= 128) {
        fwrite(STDERR, "Psalm failed with exit code $code\n");
        exit(1);
    }
}
$ns = fn(float $s): float => $s * 1e9;
echo json_encode(["monica" => [
    "latency" => ["value" => $ns($h["mean"]), "lower_value" => $ns($h["mean"] - $h["stddev"]), "upper_value" => $ns($h["mean"] + $h["stddev"])],
    "peak-memory" => ["value" => max(array_map("floatval", file("/tmp/mem.txt", FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES)))],
]], JSON_THROW_ON_ERROR);
' > "$1"
