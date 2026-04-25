---
name: psalm-task
description: "Skill for autonomously completing a development task end-to-end: understanding the task (from description or GitHub URL), implementing the fix in a worktree, reviewing with /psalm-review, iterating until clean, committing, and opening a PR. Use this skill when the user says /psalm-task, asks to 'do this task', 'implement this issue', 'fix this', or provides a GitHub issue URL and expects autonomous end-to-end completion."
argument-hint: "<task description or GitHub issue URL>"
effort: max
---

# Autonomous Task Completion

Complete a development task end-to-end — from understanding the requirement through to an open PR — with minimal user intervention.
Ultrathink tasks.

**Task input:** "$ARGUMENTS"

## Workflow

### 1. Understand the Task

Parse the argument to determine what needs to be done:

- **GitHub URL** (e.g., `https://github.com/.../issues/123`): Fetch the issue details using `gh issue view <number>` to get the title, body, and any relevant comments.
- **Text description**: Use the description directly as the task specification.
- **Should you commit and open a PR?**: optional. If not provided as text, ask at the beginning. 

Briefly summarize your understanding of the task before proceeding.
If anything is unclear, ambiguous, or seems wrong — do not guess, reinterpret, or decide on your own what I ‘probably meant.’

### 2. Choose Work Location

Unless the user explicitly specified where to work (e.g., "in a worktree" or "on this branch"), ask them:

> **Where should I work?**
> 1. **New worktree** — create an isolated branch in a git worktree
> 2. **New branch** — create a new feature branch from the master branch
> 3. **Current branch** — work directly on `<current branch name>`
>
> *(reply 1 or 2 or 3)*

Wait for the user's answer before proceeding. The user may reply with "1", "2", "3", or a text answer — interpret accordingly.

Auto-rename session (via /rename) to start name with the GitHub issue number and columns (if GH issue provided) and short overview of the task (often classname::methodname or similar idea).  

### 3. Implement

Before creating the branch/worktree, sync with the latest master:

```bash
git fetch origin master
```

This ensures the new branch/worktree is rooted on current upstream master rather than a stale local copy.

a. If the user chose **worktree** (answer: "1", "worktree", "new worktree", etc.): create a new worktree at `.Codex/worktrees/<branch-name>` using the `EnterWorktree` tool (this matches Codex's default location). If the local `master` is behind `origin/master`, fast-forward it first (`git -C <primary-repo> fetch origin && git -C <primary-repo> branch -f master origin/master`) so the worktree is created from the latest code.
b. If the user chose **new branch** (answer: "2", "new", "new branch", etc.): `git fetch origin master && git checkout -b {issue-number}-{short-issue-name} origin/master`.
c. If the user chose **current branch** (answer: "3", "current", "current branch", etc.): stay on the current branch.

Then, start task implemenation using `/feature-dev:feature-dev` skill in ultrathink mode with max effort.

In both cases:
- Analyze the codebase to understand what needs to change
- Always ask for questions to cover all gaps
- Implement the changes
- Run tests to verify
- Move to the next step

### 4. Review Loop — MANDATORY, DO NOT SKIP

**THIS STEP IS NOT OPTIONAL.** After implementation, enter a review-fix cycle (min 2 review rounds, max: 20). Do not proceed to step 5 until at least 2 review rounds have been completed.

**BLOCKING REQUIREMENT:** Steps 5 and 6 are gated behind this review loop. Proceeding to commit or PR without completing the minimum review rounds is a workflow violation.

For each round:
1. **Run `/psalm-review`** to get a comprehensive code review from domain-specific agents
2. **Read the review output** carefully — focus on Critical and Important issues
3. Fix any issues found, then repeat from step 1

At least 2 full `/psalm-review` rounds must run, even if the first round finds zero issues. The second round serves as confirmation. Only after 2 clean rounds (or after fixing all issues) may you proceed to step 5.

If issues persist after max rounds, present the remaining issues to the user and ask how to proceed.

### 5. Commit

Use it when the user asked to commit changes.
Once the review is clean, use `/a-commit` to create a well-structured commit(s) with a meaningful message(s) based on the changes made.

### 6. Sync with master

Before opening the PR, pull the latest `origin/master` and rebase onto it. Long-running tasks often see master advance in the meantime (other PRs merging); rebasing now keeps the PR reviewable and avoids a "behind master" banner on GitHub.

```bash
git fetch origin master
git rebase origin/master
```

If the rebase surfaces conflicts:
- Resolve them (small conflicts only — adjust our changes to fit new master).
- If conflicts are non-trivial or involve semantic overlap with recent merges, pause and report to the user before deciding.

After a clean rebase, re-run the core checks (`composer test:type`, `composer psalm`, `composer cs`) to confirm nothing downstream broke. Do NOT skip this — a rebase can silently invalidate a test that depended on now-changed upstream stubs or handlers.

If the branch was already pushed, the rebase rewrites history, so the next push is `git push --force-with-lease` (never plain `--force`).

### 7. Open PR

Use it when the user asked to commit changes.

Use `/psalm-pr-open` skill to create a pull request.
The PR should reference the original issue (if a GitHub URL was provided) and describe what was changed and why.

## Important Notes

- Each step builds on the previous — don't skip ahead
- If any step fails (tests don't pass, review finds blockers), fix before continuing
- The goal is a PR that's ready for human review, not perfection — minor style suggestions from the review can be left for the reviewer to decide on
- If stuck at any point, ask the user rather than spinning
