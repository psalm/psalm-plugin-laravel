---
name: psalm-pr-open
description: Create a pull request. Use when the user asks to open, create, or submit a PR.
---

# Open Pull Request

Create a pull request following project conventions.

## Instructions

### 1. Gather Context

Run these commands in parallel to gather context:

```bash
git log master..HEAD --oneline
git diff master...HEAD --stat
git status
git branch --show-current
gh auth status
```

If there are no commits ahead of base branch (`master`, unless other specified in the prompt), stop and inform the user.

### 2. Determine Issue Number

Extract the issue number from the branch name. Branch format is `ISSUE_NUMBER-short-description` (e.g., `12345-improve-pr-docs` -> `#12345`).

If the branch name does not start with a number, use `#0`.

**Sub-issues:** The orchestrator (e.g., `/implement-feature`) may pass sub-issue numbers via context. If sub-issue numbers are provided, capture them for use in the PR body.

### 3. Write PR Title

Write a short, imperative, present-tense title meaningful for release notes.
No period at the end.
Do NOT include the issue number in the title — issue references belong in the PR body only.

Example: `Add user notification preferences`

Present the title to the user and ask for confirmation before proceeding.

### 4. Write PR Body

Use the following template.

Fill in each section based on the diff and commit history.
Be concise but informative.
Assume the reviewer has zero context about the change.

Use `.github/PULL_REQUEST_TEMPLATE.md` for the template.

### 5. Push Branch

Check if the branch is already pushed to the remote:

```bash
git rev-parse --abbrev-ref --symbolic-full-name @{u} 2>/dev/null
```

If not pushed, push it:

```bash
git push -u origin HEAD
```

### 6. Create the PR

Title will be used as-is in the changelog: make it changelog ready. Wrap classnames and method names by `` for better readability.

Create the PR using `gh`:

```bash
# Write the body to a temporary file
cat <<'EOF' > /tmp/pr_body.md
BODY_CONTENT
EOF

# Create the PR using the file
gh pr create --title "TITLE" --body-file /tmp/pr_body.md

# Clean up
rm /tmp/pr_body.md
```

### 7. Self-Assign

Assign the current user as the PR assignee:

```bash
gh pr edit --add-assignee @me
```

Assign Copilot bot to review the PR.

<meta-todo>
    update the skill to mention the command to do it
</meta-todo>

### 8. Report

Output the PR URL to the user.

## Important Rules

- Do NOT add a Codex signature or `Co-Authored-By` line
- Do NOT hardcode URLs; use `gh` CLI for all GitHub operations
- If `gh` is not authenticated, stop and inform the user
