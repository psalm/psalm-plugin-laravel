# Document 3: The Analysis Phase — Type Checking Your Code

*How Psalm walks your code, tracks types, and finds bugs*

---

## Overview

After scanning builds the Storage objects (Document 2), the analysis phase walks your actual code statement by statement, tracking what type each variable has at each point, and reporting errors when types don't match expectations. This is where the real work happens.

## A Complete Example First

Before diving into the machinery, let's see the full picture. Here's how Psalm analyzes a simple function:

```php
function greet(?User $user): string {
    if ($user === null) {
        return "Hello, stranger";
    }
    return "Hello, " . $user->getName();
}
```

```
1. FunctionAnalyzer creates Context:
   $vars_in_scope = ['$user' => User|null]
   Expected return type: string

2. StatementsAnalyzer processes statements in order:

3. Statement 1: if ($user === null)
   ├── Analyze condition → generates assertion: "$user is null"
   ├── If-branch: Reconciler narrows $user → null
   │   └── return "Hello, stranger"
   │       └── "Hello, stranger" is a subtype of string → OK
   │       └── Branch terminates (return statement)
   └── After-if: NEGATE assertion → "$user is NOT null"
       └── Reconciler narrows: $user → User (removed null)

4. Statement 2: return "Hello, " . $user->getName()
   ├── $user type is now User (narrowed!)
   ├── Look up User::getName() → returns string
   ├── "Hello, " . string → result is string
   └── string is a subtype of string → OK

5. No issues found!
```

This trace shows the key concepts: Context tracking types, cloning at branches, narrowing via the Reconciler, and subtype checking. Now let's see how each part works.

## The Analyzer Hierarchy

Analysis follows a strict top-down delegation:

```
ProjectAnalyzer           — orchestrates everything
  └── FileAnalyzer        — one per file
        └── ClassAnalyzer / FunctionAnalyzer  — one per class/function
              └── StatementsAnalyzer          — walks statement lists
                    ├── ExpressionAnalyzer    — handles expressions
                    │     ├── Call\MethodCallAnalyzer
                    │     ├── Call\FunctionCallAnalyzer
                    │     ├── AssignmentAnalyzer
                    │     └── ... (~30 more expression analyzers)
                    ├── IfElseAnalyzer         — if/elseif/else
                    ├── ForeachAnalyzer        — foreach loops
                    ├── SwitchAnalyzer         — switch/match
                    ├── TryAnalyzer            — try/catch/finally
                    ├── ReturnAnalyzer         — return statements
                    └── ... (more statement analyzers)
```

Each analyzer receives a `Context` and the AST node to analyze. It may modify the Context (adding/narrowing variable types) and report issues to `IssueBuffer`.

The dispatching in `StatementsAnalyzer` is a large `match`/`if-else` chain on the AST node class — straightforward but verbose.

## Context: The Type Tracker

`Context` (`src/Psalm/Context.php`) is the single most important object during analysis. It tracks the type state at a specific point in code execution.

### Key Fields

```php
class Context {
    // The main type map: variable name → its current type
    public array $vars_in_scope = [];
    // Example: ['$user' => Union(TNamedObject(User)), '$name' => Union(TString)]

    // Variables that MIGHT exist (for "possibly undefined" checks)
    public array $vars_possibly_in_scope = [];

    // What class are we inside?
    public ?string $self = null;          // Current class FQCN
    public ?string $calling_method_id;     // e.g., "App\User::getName"

    // Control flow state
    public bool $inside_conditional = false;
    public bool $inside_loop = false;
    public bool $inside_try = false;

    // Assertions from conditions (used for type narrowing)
    public array $clauses = [];

    // Track assignments for unused variable detection
    public array $assigned_var_ids = [];
}
```

**Who creates the initial Context?** `FileAnalyzer` creates a fresh Context for each file. For top-level code, it starts with `$vars_in_scope = []` (empty). For functions/methods, the analyzer populates `$vars_in_scope` with parameter types from `FunctionLikeStorage`. For methods specifically, it also sets `$this` to the current class type in `$vars_in_scope`.

## How StatementsAnalyzer Dispatches

`StatementsAnalyzer` (`src/Psalm/Internal/Analyzer/StatementsAnalyzer.php`) is the central dispatcher:

```
StatementsAnalyzer::analyze(list<Stmt> $stmts, Context $context)
│
├── Hoist function declarations to top (PHP allows calling before declaration)
│
├── For each statement:
│   ├── Fire BeforeStatementAnalysisEvent (plugin hook)
│   │
│   ├── Dispatch to specialized analyzer:
│   │   ├── Stmt\If_        → IfElseAnalyzer::analyze()
│   │   ├── Stmt\Foreach_   → ForeachAnalyzer::analyze()
│   │   ├── Stmt\Switch_    → SwitchAnalyzer::analyze()
│   │   ├── Stmt\TryCatch   → TryAnalyzer::analyze()
│   │   ├── Stmt\Return_    → ReturnAnalyzer::analyze()
│   │   ├── Stmt\Expression → ExpressionAnalyzer::analyze()
│   │   └── ... etc
│   │
│   ├── Fire AfterStatementAnalysisEvent (plugin hook)
│   │
│   └── Track: has this statement terminated control flow? (return/throw/exit)
│
└── After all statements: check for unreferenced variables
```

## Control Flow: Cloning and Merging Context

This is where static analysis gets interesting. When code branches, Psalm must track types independently in each branch, then merge the results.

### If/Else Analysis

`IfElseAnalyzer` handles the most common branching:

```
IfElseAnalyzer::analyze(Stmt\If_ $stmt, Context $context)
│
├── 1. ANALYZE THE CONDITION
│   └── Generates assertion clauses (e.g., "$user is not null")
│
├── 2. NARROW TYPES FOR IF-BRANCH
│   └── Reconciler narrows types based on condition assertions
│       Example: if ($user !== null) → $user becomes User (drops null)
│
├── 3. ANALYZE IF-BODY with narrowed context (cloned)
│
├── 4. FOR EACH ELSEIF/ELSE:
│   ├── Clone the original context
│   ├── Apply NEGATED assertions (the condition was false)
│   └── Analyze body with negated context
│
├── 5. MERGE ALL BRANCH CONTEXTS
│   ├── Variables in both branches: combine types (Union)
│   ├── Dead-end branches (return/throw): use only surviving branch
│   └── This is why after an early return, $user is narrowed
│
└── 6. UPDATE PARENT CONTEXT with merged result
```

**Concrete example with Eloquent:**
```php
$user = User::find($id);  // Context: $user => User|null

if ($user === null) {
    abort(404);            // Dead-end branch (throws)
}

// Psalm merges: if-branch is dead (abort never returns)
// Only else-path survives → $user is narrowed to User
$user->save();             // OK: User has save()
```

### Loop Analysis

Loops are trickier because the loop body can execute multiple times, and types may change across iterations:

```
ForeachAnalyzer::analyze()
│
├── 1. Determine iterator type → extract key/value types
│   Example: foreach ($users as $user) → $user: User
│
├── 2. Analyze loop body (potentially twice)
│   └── Psalm may run 2 passes to detect type changes across iterations.
│       If a variable starts as int but could become string after one
│       iteration, Psalm widens the type for the second pass.
│       After 2 passes, types are assumed stable.
│
├── 3. Merge loop context back
│   ├── Variables assigned in loop: union of before + after types
│   └── Variables might not enter loop: mark possibly_undefined
│
└── 4. Handle break/continue effects on context
```

### Try/Catch Analysis

```
TryAnalyzer::analyze()
│
├── 1. Clone context for try body, analyze it
├── 2. For each catch block:
│   ├── Clone original (pre-try) context
│   ├── Add caught exception variable ($e => ExceptionType)
│   ├── Merge in variables that MIGHT have been assigned in try
│   └── Analyze catch body
├── 3. Analyze finally block (if present)
└── 4. Merge all paths back to parent context
```

## Expression Analysis

`ExpressionAnalyzer` dispatches expression analysis to specialized sub-analyzers. After analyzing an expression, the resulting type is stored on the AST node via `NodeDataProvider` (a hash map from AST nodes to their computed types), so parent expressions can access it.

### Method Call Analysis — A Critical Flow

When Psalm sees `$user->getName()`:

```
MethodCallAnalyzer::analyze()
│
├── 1. Get type of $user from context → User
│
├── 2. For each atomic type in the union:
│   ├── Look up ClassLikeStorage for User
│   ├── Look up MethodStorage for getName
│   ├── Check: does method exist? → or report UndefinedMethod
│   ├── Check: is method visible? → or report InaccessibleMethod
│   ├── Check argument types against parameter types
│   │   └── Is arg_type a subtype of param_type?
│   ├── Check plugins (MethodReturnTypeProviderInterface)
│   │   └── If a plugin returns a type, it OVERRIDES the declared type
│   ├── Resolve return type (substitute template params if generic)
│   └── Combine return types from all atomic types
│
└── 3. Store result type on AST node
```

**What about chained calls?** Each call in a chain `$user->posts()->where('active', true)->get()` goes through `MethodCallAnalyzer` independently. The return type of `posts()` becomes the object type for `where()`, which becomes the object type for `get()`. The chain is just nested `Expr\MethodCall` nodes in the AST.

**What about `Union` with multiple types?** If `$x` is `User|Admin`, calling `$x->getName()` looks up the method on both classes, and the return type is the union of both return types.

### How Plugins Interact with Method Analysis

When you write a `MethodReturnTypeProviderInterface`:
- Your provider completely **replaces** the return type (if it returns non-null)
- Psalm still checks argument types against parameter types — you don't bypass that
- Your provider runs for the registered class AND all subclasses
- If your provider returns `null`, Psalm falls back to the declared return type

## Type Narrowing: The Reconciler

The `Reconciler` (`src/Psalm/Type/Reconciler.php`) narrows types based on conditions:

```php
if ($x instanceof User) { ... }
if (is_string($y)) { ... }
if ($z !== null) { ... }
if (!empty($arr)) { ... }
```

Each condition generates **assertions**, and the Reconciler produces narrowed types:

```
Input:  $user has type User|null, assertion is "!null"
Output: $user has type User

Input:  $x has type string|int, assertion is "string"
Output: $x has type string

Input:  $x has type string|int, assertion is "!string" (negated, for else-branch)
Output: $x has type int
```

### Clause-Based Reasoning

Simple narrowing (`if ($x !== null)`) could be handled with ad-hoc rules. But PHP conditions can be complex:

```php
if (($x instanceof A && method_exists($x, 'foo')) || is_string($x)) {
    // What do we know about $x here?
}
```

To handle all combinations correctly, Psalm models conditions as **propositional logic** — specifically, clauses in conjunctive normal form (AND of ORs):

```
// if ($x instanceof A || $y !== null)
// Becomes one clause with two alternatives (OR):
Clause: ['$x is A' OR '$y is !null']

// if ($x instanceof A && $y !== null)
// Becomes two separate clauses (AND):
Clause 1: ['$x is A']
Clause 2: ['$y is !null']
```

The algebra system (`Algebra.php`) can negate these for else-branches, simplify redundancies, and derive implied assertions. You rarely need to understand this for plugin development, but it explains why Psalm handles complex conditions correctly.

## Issue Reporting

When an analyzer detects a problem, it creates an Issue object and adds it to `IssueBuffer`:

```php
IssueBuffer::maybeAdd(
    new UndefinedPropertyFetch(
        'Property App\Models\User::$emial is not defined',
        new CodeLocation($source, $stmt),
        'App\Models\User::$emial',  // property_id
    ),
    $source->getSuppressedIssues(),
);
```

This appears in your terminal as:
```
ERROR: UndefinedPropertyFetch - app/Services/UserService.php:42:10
  Property App\Models\User::$emial is not defined
```

`IssueBuffer` handles:
- Suppression via `@psalm-suppress` annotations
- Error level filtering (error vs. warning based on config)
- Plugin hooks (`BeforeAddIssueInterface` — plugins can suppress issues)

**Can plugins add custom issues?** Yes — create a class extending `Psalm\Issue\PluginIssue` and add it via `IssueBuffer::maybeAdd()`.

---

*Next: [Document 4 — Type System Internals](04-type-system.md)*
