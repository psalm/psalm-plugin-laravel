# Document 1: Psalm Architecture Overview

*For senior PHP developers new to static analysis internals*

---

## What Psalm Actually Does

You use Psalm as a CLI tool: you run `psalm` and it tells you about type errors in your PHP code. But what happens between "run command" and "see errors"? This document gives you the full picture.

Psalm is a **static analysis tool** — it reads your PHP source code *without executing it* and reasons about types, control flow, and data flow to find bugs. It does this in two major phases: **Scan** then **Analyze**.

## The Two-Phase Pipeline

```
┌─────────────────────────────────────────────────────────────┐
│                     PHASE 1: SCANNING                       │
│                                                             │
│  PHP Files ──► nikic/php-parser ──► AST ──► ReflectorVisitor│
│                                              │              │
│                                    ┌─────────┴──────────┐   │
│                                    │  FileStorage       │   │
│                                    │  ClassLikeStorage   │   │
│                                    │  FunctionLikeStorage│   │
│                                    └─────────┬──────────┘   │
│                                              │              │
│                                         Populator           │
│                                    (resolve inheritance)    │
└─────────────────────────────────────────────────────────────┘
                          │
                          ▼
┌─────────────────────────────────────────────────────────────┐
│                     PHASE 2: ANALYSIS                       │
│                                                             │
│  ProjectAnalyzer                                            │
│    └── FileAnalyzer                                         │
│          └── StatementsAnalyzer  ◄── Context ($vars_in_scope)│
│                ├── IfElseAnalyzer     (clone & merge context)│
│                ├── ForeachAnalyzer                           │
│                ├── ExpressionAnalyzer                        │
│                │     ├── Call\MethodCallAnalyzer             │
│                │     ├── Call\FunctionCallAnalyzer           │
│                │     └── AssignmentAnalyzer                  │
│                └── ReturnAnalyzer                            │
│                                                             │
│                          │                                  │
│                     IssueBuffer                             │
│               (queue where errors accumulate)               │
└─────────────────────────────────────────────────────────────┘
```

### Why Two Phases?

PHP allows forward references — you can use a class before it's declared, call a function defined later in the file, or extend a class from another file. Psalm can't analyze a method call if it hasn't yet seen the method's signature. So it first **scans** everything to build a complete picture of all declarations, then **analyzes** the actual logic.

Think of scanning as discovering what exists — like running `php artisan ide-helper:models` to learn about all your models' methods and properties. Analysis is then checking whether your code actually uses those things correctly.

## Background: ASTs and the Visitor Pattern

### Abstract Syntax Trees (ASTs)

When Psalm reads a PHP file, it doesn't work with raw text. It uses **nikic/php-parser** to parse the source into an **Abstract Syntax Tree** — a tree of objects representing the code structure.

```php
// This PHP code:
$x = strlen($name);

// Becomes an AST roughly like:
Stmt\Expression
  └── Expr\Assign
        ├── left: Expr\Variable("x")
        └── right: Expr\FuncCall
              ├── name: Name("strlen")
              └── args: [Expr\Variable("name")]
```

Each node is a PHP object (e.g., `PhpParser\Node\Expr\Assign`). Psalm walks these trees using the **Visitor pattern**.

### The Visitor Pattern

nikic/php-parser provides a `NodeTraverser` that walks the AST. You give it a `NodeVisitor` — an object with `enterNode()` and `leaveNode()` methods. The traverser calls these methods for every node in the tree. If you've used Laravel's pipeline middleware, the Visitor pattern is similar — each node passes through a handler that can inspect or modify it.

Psalm's `ReflectorVisitor` is a visitor used during scanning. It "visits" every AST node and extracts type information (class declarations, method signatures, property types, etc.) into storage objects.

## The Two Phases in Detail

### Phase 1 Output: Storage Objects

Scanning produces three main data structures — Psalm's internal database of everything it knows about your code:

| Storage Object | What It Stores | Example |
|---|---|---|
| `FileStorage` | Per-file info: functions, constants, classes declared, type aliases | `app/Models/User.php` has class `User` |
| `ClassLikeStorage` | Per-class info: properties, methods, interfaces, traits, template params | `User` extends `Model`, has method `save(): bool` |
| `FunctionLikeStorage` | Per-function/method info: parameters, return type, template params | `strlen` takes `string`, returns `int` |

These are populated during scanning and read heavily during analysis. They are the bridge between the two phases. Document 2 covers them in detail.

### Phase 2 Mechanism: Context

During analysis, Psalm tracks what type each variable has at every point in your code using a `Context` object. The key field is `$vars_in_scope`:

```php
// Example Context state at some point during analysis:
// $vars_in_scope = [
//     '$user' => User,          (internally: Union containing TNamedObject)
//     '$name' => string,        (internally: Union containing TString)
//     '$count' => int,          (internally: Union containing TInt)
// ]
```

(The `Union` and `TNamedObject` notation represents Psalm's internal type objects — covered in Document 4. For now, read them as the PHP types you know.)

When Psalm encounters a branch (`if`, `switch`, `try/catch`), it **clones** the Context (a PHP `clone`, deep-copying the type map), analyzes each branch independently, then **merges** the contexts back together. This is how it tracks that a variable might be `string|null` after an if/else. Document 3 covers this mechanism in depth.

## Directory Layout

Here's where things live in the codebase, annotated with what matters for plugin developers:

```
src/Psalm/
├── Internal/
│   ├── Analyzer/           # Phase 2: statement/expression analyzers
│   │   ├── Statements/     # Control flow: IfElseAnalyzer, ForeachAnalyzer, etc.
│   │   └── Expr/           # Expressions: method calls, assignments, casts, etc.
│   ├── Codebase/           # Orchestration: Scanner, Populator, ClassLikes, Functions
│   ├── Provider/           # File I/O, caching, storage access
│   ├── PhpVisitor/         # ReflectorVisitor (scanning phase AST visitor)
│   ├── Type/               # Type operations: parsing, comparison, templates
│   │   └── Comparator/     # Subtype checking logic
│   ├── Fork/               # Parallelism via pcntl_fork()
│   └── Scanner/            # FileScanner (orchestrates per-file scanning)
├── Type/
│   ├── Union.php           # Union type: the main type container
│   └── Atomic/             # Dozens of concrete type classes ★ YOU'LL USE THESE IN PLUGINS
├── Storage/                # Storage objects ★ YOUR PLUGIN READS/MODIFIES THESE
├── Plugin/                 # Plugin API: events, hooks ★ YOUR PLUGIN IMPLEMENTS THESE
├── Issue/                  # All issue types (InvalidArgument, UndefinedVariable, etc.)
├── Config/                 # psalm.xml configuration handling
└── Context.php             # Variable type tracking during analysis
```

The starred (★) directories are where plugin developers spend most of their time.

## The Full Lifecycle

Here's what happens when you run `psalm`:

1. **Configuration** — `Config` reads `psalm.xml`, determines which directories to scan, error levels, plugins to load
2. **Plugin Loading** — Plugins are instantiated and register their event handlers
3. **File Discovery** — Psalm determines which files are "project files" (listed in your `psalm.xml` `<projectFiles>`) and which are "dependency files" (vendor, etc. — scanned for signatures only)
4. **Scanning** — Each file is parsed to AST, then `ReflectorVisitor` extracts declarations into Storage objects
5. **Population** — The `Populator` resolves inheritance: copies parent methods to child classes, resolves trait use, handles interface implementations
6. **Analysis** — Each project file is analyzed statement-by-statement. The analyzer tracks types through `Context`, clones/merges at branches, and reports issues to `IssueBuffer`
7. **Output** — Collected issues are formatted and displayed

> **Try it yourself:** In a Laravel project with `psalm/plugin-laravel` installed, run `vendor/bin/psalm --debug` and you'll see each of these phases in the output — file scanning, population, then analysis with timing information.

## How This Connects to Plugin Development

As a plugin developer, you interact with this pipeline at specific hook points:

- **During scanning** (`AfterClassLikeVisitInterface`): Psalm has just scanned a class and built its `ClassLikeStorage`. Your plugin gets a chance to modify that storage — for example, the Laravel plugin uses this to add virtual `scopeActive()` methods and `$email` properties to Eloquent models so Psalm doesn't report them as undefined.

- **After scanning** (`AfterCodebasePopulatedInterface`): All type information is loaded and inheritance is resolved. You can do bulk modifications here.

- **During analysis** (`Before/AfterStatementAnalysisInterface`, `Before/AfterExpressionAnalysisInterface`): Intercept specific code patterns, modify types, suppress or add issues.

- **Type providers** (`MethodReturnTypeProviderInterface`, `PropertyTypeProviderInterface`): Dynamically compute types for magic methods/properties. For example, making `User::find($id)` return `User|null` instead of `mixed`.

The next documents dive deep into each phase.

---

*Next: [Document 2 — Scanning Phase Deep Dive](02-scanning-phase.md)*
