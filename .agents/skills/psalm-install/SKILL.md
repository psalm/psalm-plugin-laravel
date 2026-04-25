---
name: psalm-install
description: >
  Audit a real-world Laravel application with psalm-plugin-laravel: fork the repo, install the plugin,
  run Psalm analysis (types + taint/security), categorize findings, discover plugin gaps, and offer to
  create a PR on the target repo with Psalm+plugin configured, CI integration, and a baseline.
  Use this skill when the user says "audit", "scan", "analyze", "test the plugin on", "run psalm on",
  "try the plugin on", "psalm-install", or mentions a Laravel repo to evaluate.
  Also use when the user wants to find plugin gaps, do outreach to Laravel projects, generate
  security audit content for marketing, or draft a blog post from audit findings.
args: "<owner/repo or GitHub URL>"
---

# Audit a Laravel Application with psalm-plugin-laravel

Fork a public Laravel repo, install this plugin, run full Psalm analysis (type + taint),
categorize every finding, discover what's missing in the plugin, and offer to open a PR
on the target repo introducing Psalm as a security scanner.

## Argument

This skill requires a single argument: the target repository.

Supported formats:
- Repo slug: `BookStackApp/BookStack`
- GitHub URL: `https://github.com/monicahq/monica`

Parse the argument from `$ARGUMENTS`. Extract `OWNER/REPO` from either format.
If no argument is provided, ask the user for a repo.

## Why This Skill Exists

This serves two goals simultaneously:
1. **Find plugin gaps** — real-world apps surface missing stubs, false positives, and unsupported
   Laravel patterns that the plugin should handle. Each audit feeds back into plugin development.
2. **Outreach** — a well-crafted PR introducing Psalm + security scanning to a popular Laravel app
   builds credibility and adoption. This is the #1 growth strategy from the marketing playbook
   (see `.alies/docs/marketing-strategy-security-niche.md`).

## Workflow

### Phase 1: Setup

#### 1.1 Fork and Clone the Repository

```bash
# Fork to the user's account and clone the fork (not the original)
gh repo fork OWNER/REPO --clone=true -- /tmp/psalm-audit-REPO --depth=1
cd /tmp/psalm-audit-REPO
```

If the repo is already forked, clone the existing fork:
```bash
FORK_USER=$(gh api user --jq '.login')
gh repo clone "$FORK_USER/REPO" /tmp/psalm-audit-REPO --depth=1
cd /tmp/psalm-audit-REPO
```

#### 1.2 Verify It's a Laravel App

Check `composer.json` for `laravel/framework` in `require`. If not found, stop and tell the user.

Also check:
- PHP version constraint (must be compatible with PHP 8.2+)
- Whether `larastan/larastan` or `nunomaduro/larastan` is already installed (note this for later)
- Whether `vimeo/psalm` is already installed
- Laravel version (must be 12+ for plugin v4.x)
- PHPStan level: check `phpstan.neon` or `phpstan.neon.dist` for `level:`. Note the value for step 1.4

If the Laravel version is too old (< 12), tell the user and suggest using plugin v3.x instead, or skip.

#### 1.3 Install Dependencies + Plugin

First install existing dependencies, then add Psalm:

```bash
# Install existing dependencies (needed for psalm --init and Laravel app boot)
composer install --no-interaction --prefer-dist --no-scripts

# Set up Laravel environment if needed
if [ -f .env.example ] && [ ! -f .env ]; then
  cp .env.example .env
  php artisan key:generate --no-interaction 2>/dev/null || true
fi

# Install Psalm + plugin — ALWAYS use the latest major version (v4.x requires Psalm 7)
# Psalm 7 is currently in beta, so we need minimum-stability:beta + prefer-stable
composer config minimum-stability beta
composer config prefer-stable true
composer require --dev "psalm/plugin-laravel:^4.2" --no-interaction -W
```

**Important:** Always pin `psalm/plugin-laravel:^4.2` (or the latest 4.x). Without pinning,
Composer resolves v3.x (Psalm 6) which is the old version. The `^4.2` constraint automatically
pulls in `vimeo/psalm` v7 as a dependency, so there's no need to require Psalm separately.
Use `-W` to allow Composer to update other dependencies if needed for compatibility.

Since Psalm 7 is currently in beta, `minimum-stability: beta` and `prefer-stable: true` must
be set first. Once Psalm 7 reaches stable, these two `composer config` lines can be removed
from the skill.

If there are dependency conflicts, report the conflict to the user — this itself is useful data
(compatibility gap).

#### 1.4 Configure Psalm

Read the reference template from `references/psalm-xml-template.md` and adapt it to the project.

Choose `errorLevel` based on the project's PHPStan configuration:
- If PHPStan is installed at **max level** (level 9 or `max`): use `errorLevel="1"` — the codebase
  is already strictly typed, so Psalm level 1 will produce meaningful findings, not noise.
- Otherwise: use `errorLevel="3"` — good balance between noise and usefulness for most codebases.

Always include the `MissingOverrideAttribute` and `MissingPureAnnotation` (and related purity)
issueHandlers from the template — these fire at all levels in Psalm 7 and are too noisy for audits.

Check which directories and files exist before including them in `<projectFiles>`:
- `app/`, `bootstrap/`, `config/`, `database/`, `routes/` — include if present
- `artisan` or `artisan.php` — include whichever exists (Laravel 11+ uses `artisan` without `.php`)
- `public/index.php` — include if present
- `bootstrap/cache` — always ignore

For the **audit run**, use `<failOnInternalError>true</failOnInternalError>` on the plugin
to surface plugin bugs (Bucket B findings). For the **PR** (Phase 6), remove this setting
so the plugin doesn't break their CI if it has an internal error.

### Phase 2: Run Analysis

#### 2.1 Run Full Psalm Analysis

In Psalm 7, `--taint-analysis` runs both type checking and taint analysis in a single pass.
One run is enough:

```bash
php -d memory_limit=-1 ./vendor/bin/psalm \
  --no-cache \
  --no-diff \
  --no-progress \
  --no-suggestions \
  --output-format=json \
  --taint-analysis \
  2>&1 | tee /tmp/psalm-audit-REPO-output.json
```

If taint analysis causes memory issues or takes too long (>15 minutes), fall back to type-only:

```bash
php -d memory_limit=-1 ./vendor/bin/psalm \
  --no-cache --no-diff --no-progress --no-suggestions --output-format=json \
  2>&1 | tee /tmp/psalm-audit-REPO-types.json
```

Then try taint separately with a higher memory limit or on a subset of files.

Extract summary stats from the JSON output (issue counts by type, total files analyzed)
rather than running Psalm a second time.

### Phase 3: Analyze Findings

This is the most important phase. Categorize every finding into one of these buckets:

#### Bucket A: Real Security Findings (Taint Issues)

These are genuine vulnerabilities the plugin correctly detected:
- `TaintedSql`, `TaintedShell`, `TaintedHtml`, `TaintedFile`, `TaintedSSRF`, etc.
- These prove the plugin's value and go in the PR description as "what psalm-plugin-laravel found"

For each, note:
- File and line
- The taint flow (source -> ... -> sink)
- Severity (how exploitable is this?)
- Whether Larastan could have caught it (answer: no, it can't do taint analysis)

#### Bucket B: Plugin Gaps (False Positives from Missing Stubs/Handlers)

These are errors caused by the plugin not understanding a Laravel pattern:
- Errors on well-known Laravel methods (e.g., missing return types on Eloquent)
- False `UndefinedMethod`, `UndefinedPropertyFetch` on valid Laravel magic
- Type errors on facades, helpers, or container resolution
- Missing taint annotations (should be a source/sink but isn't)
- Internal plugin errors (surfaced by `failOnInternalError`)

For each, note:
- The Laravel feature/pattern that's unsupported
- Whether this is a missing stub, missing handler, or Psalm core limitation
- Impact (how many apps would hit this?)
- Whether a fix is feasible

#### Bucket C: Real Type Issues in the App

Genuine bugs or type safety issues in the application code:
- `InvalidArgument`, `InvalidReturnType`, `PossiblyNull`, etc.
- These aren't plugin bugs but show the plugin provides value beyond security

#### Bucket D: Noise (Expected at errorLevel 3)

Issues that are too strict or not actionable:
- `MixedAssignment`, `MixedMethodCall` from untyped code
- Issues in vendor code or generated files
- Known Psalm quirks

### Phase 4: Report

Present the findings to the user in a structured report:

```
## Audit Report: OWNER/REPO

### Summary
- Total issues: X
- Security findings (taint): Y
- Plugin gaps: Z
- Real type issues: W
- Noise (filtered): N

### Security Findings (Bucket A)
[List each with file, line, taint flow, severity]

### Plugin Gaps Discovered (Bucket B)
[List each with the Laravel pattern, proposed fix, impact]

### Interesting Type Issues (Bucket C)
[Top 5-10 most interesting/impactful]

### Larastan Comparison
- Larastan installed: yes/no
- If yes: note that Larastan provides TYPE analysis (model-property, view-string, etc.)
  but CANNOT detect any of the security findings above. They are complementary.
- If no: note that the project would benefit from BOTH tools.
```

### Phase 5: Create GitHub Issues for Plugin Gaps

For each item in Bucket B, ask the user if they want to create a GitHub issue on
`psalm/psalm-plugin-laravel`. Draft the issue with:

```markdown
## Description
[What Laravel pattern is unsupported]

## Reproduction
Found while auditing [REPO_URL]:
- File: `path/to/file.php:LINE`
- Code: [minimal code snippet]

## Expected Behavior
[What the plugin should do]

## Actual Behavior
[What error appears]

## Proposed Fix
[stub file to add/modify, handler to update, etc.]
```

Use label `enhancement` for missing features, `bug` for incorrect behavior.

### Phase 6: Create PR on Target Repo (Optional)

Ask the user: "Would you like me to create a PR on OWNER/REPO introducing Psalm + security scanning?"

If yes, create a branch and PR with these files:

#### psalm.xml

Use the reference template but with these PR-specific adjustments:
- **Remove** `<failOnInternalError>true</failOnInternalError>` — a plugin bug should not break their CI
- Keep the errorLevel chosen in Phase 1.4

#### psalm-baseline.xml

Generate a baseline that suppresses ALL current issues so the PR is "green":

```bash
php -d memory_limit=-1 ./vendor/bin/psalm --set-baseline=psalm-baseline.xml --no-cache
```

#### .github/workflows/psalm.yml

Read the reference CI template from `references/ci-template.md` and adapt it.

#### PR Description

The PR description should:

1. **Lead with security** — mention specific taint findings (Bucket A) as proof of value
2. **Be respectful** — this is an unsolicited PR, so be humble and helpful
3. **Explain the baseline** — "All current issues are baselined so CI passes. You can fix them incrementally."
4. **Address the Larastan question** — if Larastan is installed:
   > This project already uses Larastan for type analysis — great choice! psalm-plugin-laravel
   > complements Larastan with **security scanning (taint analysis)** that PHPStan structurally
   > cannot provide. The two tools catch different categories of issues:
   > - **Larastan**: type safety, model property validation, view existence, collection optimization
   > - **psalm-plugin-laravel**: SQL injection, XSS, SSRF, shell injection, file traversal, open redirects
   >
   > Running both gives you the most comprehensive static analysis coverage for Laravel.

5. **Link to documentation** — link to the plugin's README security section
6. **Keep it actionable** — explain next steps (review baseline, fix issues incrementally, customize config)

Use `/psalm-pr-open` conventions for the PR format but adapt the body for external repos.

### Phase 7: Responsible Disclosure (If Real Vulnerabilities Found)

If Bucket A contains findings that look exploitable (not just theoretical taint flows), **do NOT
disclose them publicly** — not in the PR, not in GitHub issues, not in blog posts.

#### 7.1 Assess Severity

For each Bucket A finding, classify:
- **Exploitable**: user input reaches a dangerous sink with no sanitization, and the route is
  publicly accessible. Examples: raw SQL with `$request->input()`, `Process::run()` with user data.
- **Theoretical**: taint flow exists but is mitigated by middleware, validation, or access control
  that Psalm can't see. Still worth reporting but lower urgency.

#### 7.2 Contact Maintainers Privately

```
# Check for a security policy first
gh api repos/OWNER/REPO/contents/SECURITY.md --jq '.content' | base64 -d 2>/dev/null
```

If SECURITY.md exists, follow its instructions. Otherwise:
1. Check if the repo has "Private vulnerability reporting" enabled (GitHub Settings > Security)
2. If yes, use `gh api repos/OWNER/REPO/security-advisories` to create a private advisory
3. If no, find maintainer contact (email from commits, Twitter DM, or open a vague issue asking
   "Do you have a security contact?")

Draft a disclosure message:

```
Subject: Security finding in REPO — SQL injection / shell injection / etc.

Hi [maintainer],

While testing psalm-plugin-laravel (a free security scanner for Laravel) on popular
open-source projects, I found a potential [vulnerability type] in [file:line].

[Brief description of the taint flow WITHOUT a full exploit]

I'd like to share full details privately so you can fix this before any public disclosure.
What's the best way to share the details securely?

— [Your name]
```

#### 7.3 Disclosure Timeline

- **Day 0**: Private report to maintainer
- **Day 7**: Follow up if no response
- **Day 30**: Second follow up, mention 90-day disclosure standard
- **Day 90**: Public disclosure is standard practice (per Google Project Zero / CERT norms)
- If maintainer fixes it sooner, coordinate on joint disclosure timing

#### 7.4 After Disclosure

Once the vulnerability is fixed and public:
- Request a CVE via GitHub's advisory system or MITRE
- This is the most valuable marketing artifact possible — a real CVE found by the tool
- Use it in the blog post (Phase 8) with the maintainer's acknowledgment

### Phase 8: Blog Post Draft (Optional)

Ask the user: "Would you like me to draft a blog post from these findings?"

If yes, generate a draft following the marketing skill's blog structure
(see `.Codex/skills/psalm-marketing/SKILL.md`):

#### Structure

```markdown
# How psalm-plugin-laravel found [vulnerability type] in [APP_NAME]

[Hook — 2-3 sentences establishing why this matters. Reference the app's popularity
and the type of data it handles.]

## The finding

[Show the taint flow with actual code from the app (sanitized if pre-disclosure).
Explain what Psalm detected and why it's dangerous.]

## Why pattern-matching tools miss this

[Explain the cross-function dataflow that makes this invisible to grep-based scanners
and Semgrep free tier. This is the key differentiator.]

## How to scan your own Laravel app

\`\`\`bash
composer require --dev psalm/plugin-laravel
./vendor/bin/psalm --init
./vendor/bin/psalm-plugin enable psalm/plugin-laravel
./vendor/bin/psalm
\`\`\`

Security scanning runs automatically in Psalm 7. No extra flags needed.

## How it compares

[Brief comparison table: psalm-plugin-laravel vs Larastan vs commercial tools.
Emphasize: free, dataflow-based, Laravel-aware, complementary to Larastan.]
```

#### Rules for blog content
- **Never publish exploit details before disclosure is complete** (Phase 7)
- If the finding is pre-disclosure, draft with `[REDACTED]` placeholders and a note to fill in after fix
- Credit the app's maintainers positively — "we scanned [app] because it's popular and well-maintained"
- Don't be sensational — be factual about what the tool found
- Include the cross-function dataflow argument (the key differentiator vs pattern matchers)
- End with install instructions and a link to the README
- Target publications: dev.to, Laravel News, Psalm blog

## Important Rules

- Never commit secrets, `.env` files, or credentials from the target repo
- Never push to the target repo's main branch — always create a feature branch
- If the repo has a CONTRIBUTING.md, read it and follow its guidelines
- Be conservative with security findings — only report taint issues you're confident about
- The audit workspace (`/tmp/psalm-audit-*`) is temporary — save important findings before cleanup
- Always ask before creating issues or PRs — the user decides what goes public
- Frame everything as "complementary to Larastan", never as "replacement for Larastan"