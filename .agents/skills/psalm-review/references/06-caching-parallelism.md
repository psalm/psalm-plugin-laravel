# Document 6: Caching, Parallelism & Performance

*How Psalm handles large codebases — and what plugin developers must know*

---

## What Plugin Developers Must Know (Read This First)

Before diving into internals, here are the critical facts for plugin developers:

### 1. Your Plugin Code Runs in Forked Workers

Psalm uses `pcntl_fork()` to parallelize analysis. Each worker process gets an **independent copy** of memory. Changes in one worker are invisible to other workers and the parent process.

### 2. Static State in Your Plugin is Per-Worker

```php
class MyHandler implements AfterExpressionAnalysisInterface
{
    private static array $discovered_routes = [];

    public static function afterExpressionAnalysis(...): ?bool
    {
        // BUG: This array is only visible to THIS worker!
        // Other workers have their own copy.
        // The parent process has an empty copy.
        self::$discovered_routes[] = $route;
        return null;
    }
}
```

> **Common plugin bug**: Accumulating data in a `static` property during analysis hooks, then trying to read it in `AfterAnalysisInterface`. By the time `AfterAnalysis` runs in the parent process, the static property is **empty** — the data was accumulated in worker processes that have already exited. Use `ClassLikeStorage::$custom_metadata` instead (it gets serialized back to the parent).

### 3. Which Hooks Run Where?

| Hook | Runs In | Safe for Static State? |
|---|---|---|
| `AfterClassLikeVisit` | Workers (if parallel scanning) | No — use $custom_metadata |
| `AfterCodebasePopulated` | Parent, single-threaded | Yes |
| `Before/AfterStatementAnalysis` | Workers | No |
| `Before/AfterExpressionAnalysis` | Workers | No |
| Type providers | Workers | No |
| `AfterAnalysis` | Parent, single-threaded | Yes (but workers' static state is gone) |

### 4. Debugging Tips

See Document 5's "Debugging and Testing Your Plugin" section for full debugging guidance. Key flags: `--threads=1` (single-threaded), `--no-cache` (fresh analysis), `--debug` (timing info).

### 5. Performance Rules for Hooks

**Do:**
- Return `null` quickly for cases you don't handle — hooks fire for EVERY statement/expression
- In type providers, check `$method_name` or `$property_name` first before expensive lookups
- Cache expensive lookups in static properties (safe since per-worker, reset each run)
- Use `AfterCodebasePopulated` for one-time initialization
- Use stub files for static type information (scanned once, cached)

**Don't:**
- Perform I/O (filesystem, network, database) in per-statement/expression hooks
- Build large data structures expecting them to survive across workers
- Throw exceptions from hooks — return `null` or `false` instead

---

## How Caching Works

Psalm caches aggressively to avoid redoing work on subsequent runs.

### What Gets Cached

| Cache Layer | What's Cached | Invalidation |
|---|---|---|
| **Parser cache** | Parsed AST from php-parser | File content hash changes |
| **File Storage cache** | `FileStorage` objects | File content hash changes |
| **Class Storage cache** | `ClassLikeStorage` objects (including `$custom_metadata`) | File content hash changes |

All caches are file-system based, stored in your project's cache directory (configurable via `<cacheDirectory>` in `psalm.xml`).

### Content-Based Invalidation

Each cached item has a content hash (using `xxh128`, a fast non-cryptographic hash):

```
Write: hash = xxh128(file_contents) → store alongside cached data
Read:  current_hash = xxh128(file_contents)
       if current_hash === stored_hash → cache hit
       else → cache miss, re-scan
```

Only actual content changes invalidate the cache — renaming a file or touching its modification time doesn't matter.

### Global Cache Invalidation

The entire cache directory includes a hash of:
- `composer.lock` content
- Psalm's own Storage class definitions (their file modification times)
- Serializer type (igbinary vs. native PHP serialize)
- Your psalm.xml config

If you update Psalm itself, install/remove packages, or change config, the entire cache invalidates. This is coarse-grained but safe.

### Serialization

Psalm prefers **igbinary** (a PHP extension for fast binary serialization) when available. If not installed, it falls back to PHP's native `serialize()`. igbinary is significantly faster and produces smaller cache files — worth installing for large projects.

### File Locking

Since multiple Psalm workers may access the cache simultaneously:
- **Reading**: shared lock (`flock(LOCK_SH)`) — multiple readers OK
- **Writing**: exclusive lock (`flock(LOCK_EX)`) — one writer at a time
- **Contention**: retries up to 5 times with 50ms delay

This prevents cache corruption from parallel workers.

## How Parallelism Works

### The Forking Model

```
                    ┌── Worker 1: analyze files A, B, C
                    │
Parent Process ─────┼── Worker 2: analyze files D, E, F
(orchestrator)      │
                    ├── Worker 3: analyze files G, H, I
                    │
                    └── Worker 4: analyze files J, K, L

                    Workers send results back via IPC
                    (Inter-Process Communication — socket pairs)

                    Parent merges all results
```

### How It Works Under the Hood

Psalm uses the **Amp** async framework (a popular PHP async library) to manage worker processes:

1. **Parent** creates IPC socket pairs, calls `pcntl_fork()`
2. **Child** (worker) inherits parent's entire memory via copy-on-write (CoW)
3. Worker runs its assigned task (scanning or analysis)
4. Worker serializes results, sends via IPC socket
5. Worker exits
6. Parent receives results, merges them
7. Repeat until all work is done

### Data Flow Between Workers

```
BEFORE FORK:
├── Parent has all Storage objects loaded (from cache or scanning)
├── All ClassLikeStorage, FileStorage loaded into static arrays
└── Workers inherit this complete state

DURING ANALYSIS (in workers):
├── Workers work independently — completely isolated memory
├── Each worker analyzes its assigned files
├── Issues collected in worker's local IssueBuffer

AFTER WORKERS FINISH:
├── Parent collects serialized results from each worker
├── Merges issue lists
├── Merges file manipulation data (for auto-fix)
└── Workers' static state is discarded (processes exited)
```

### Memory Implications

Each forked worker inherits the parent's memory via copy-on-write. On a large codebase (100K+ lines), the parent process may use 500MB+ of RAM. Workers only consume additional memory for data they modify — CoW keeps the actual footprint manageable. But with many threads on a large codebase, total memory usage can be significant.

### Thread Configuration

```xml
<!-- psalm.xml -->
<psalm threads="4" />
```

Or via CLI: `psalm --threads=4`. Use `--threads=1` during plugin development.

Psalm only forks when beneficial: more than 1 thread requested AND more files than threads. A typical Laravel app with ~200 files benefits from 2-4 threads.

## Incremental Analysis

When you change a file and re-run Psalm:

```
1. Check file hashes against cache
   ├── Unchanged files: skip scanning, use cached Storage
   └── Changed files: re-scan, update Storage

2. Determine analysis scope
   ├── Changed files: always re-analyze
   ├── Dependent files: re-analyze (files that reference changed classes)
   └── Unaffected files: skip, use cached results

3. Re-analyze only the affected files
```

**Dependency tracking**: Psalm tracks which files reference which classes/functions via `FileReferenceProvider`. When a class changes, all files using that class are marked for re-analysis. This is why changing a base model class can trigger re-analysis of many files.

## Summary: What Happens on Each Run

```
Cold run (no cache):
├── Parse all files → cache ASTs
├── Scan all files in parallel → cache Storage objects
├── Populate inheritance (single-threaded)
├── Fire AfterCodebasePopulated hooks
├── Analyze all files in parallel (workers)
├── Merge results, report issues
└── ~30-60 seconds on a typical Laravel app

Warm run (cached, no changes):
├── Check file hashes → all cached → skip scanning
├── Check analysis cache → all cached
└── "No files to analyze" → ~1-2 seconds

Incremental run (one file changed):
├── Re-scan changed file → update Storage
├── Re-populate affected classes
├── Re-analyze changed file + dependents
└── ~2-5 seconds on a typical Laravel app
```

## Next Steps

After reading all 6 documents, you understand Psalm's internals. Here's how to start contributing:

1. **Set up the development environment**: Clone the repo, run `composer install`, run `composer tests` to verify everything works

2. **Recommended source code reading order:**
   - Start with `src/Psalm/Context.php` — small, central, easy to understand
   - Then `src/Psalm/Internal/Analyzer/StatementsAnalyzer.php` — the dispatcher
   - Pick one analyzer like `Statements/IfElseAnalyzer.php` to see clone/merge in action
   - Read `src/Psalm/Plugin/EventHandler/` for the interfaces you'll implement

3. **Look at psalm-plugin-laravel**: Study the existing Laravel plugin to see these patterns in real code. Key files to start with:
   - The main plugin entry point and how it registers handlers
   - The Eloquent model handler (how it adds properties/methods)
   - The facade handler (how it resolves facade calls)

4. **Run Psalm's own tests**: `composer phpunit` runs all tests. For a single test: `php vendor/bin/phpunit tests/ClosureTest.php`

5. **Test your changes**: Create test cases following the `ValidCodeAnalysisTestTrait` / `InvalidCodeAnalysisTestTrait` patterns shown in Document 5

---

*End of internals documentation series.*
