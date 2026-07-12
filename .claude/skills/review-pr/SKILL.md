---
name: review-pr
description: >-
  Reviews a GitHub pull request: fetches the diff, checks for code quality,
  convention violations, test coverage, and runs the local quality checks.
  Activate when the user asks to review a PR, check a pull request, or audit
  incoming changes.
---

# PR Review Skill — Crockenhill Baptist Church

Follow these steps exactly when reviewing a pull request.

## 1. Identify the PR

- If the user provided a PR number or URL, use that.
- Otherwise, run `gh pr list` to show open PRs and ask the user which one.

## 2. Fetch PR context

Run these in parallel. Always use `--json` for `gh pr view` — the plain text output includes a noisy GraphQL deprecation warning that pollutes results.

```bash
gh pr view {number} --json title,body,author,headRefName,baseRefName,state   # metadata
gh pr diff {number}                                                            # full diff
```

## 3. Review the diff

Work through the diff systematically. Check for:

### Necessity (check this first — it can end the review early)

- **Do-not-invest list:** if the PR touches anything in `AGENTS.md` § "Autonomous fleet status &
  the do-not-invest list" (code the July 2026 simplification backlog schedules for deletion or
  rewrite), that is an automatic **decline** regardless of code quality. Say which backlog item
  covers the code.
- **Worth-it gate (autonomous PRs only):** every unattended autonomous PR — including Jules,
  Codex, Gemini, and Claude Code — must state **Who benefits** and **What observably improves**
  in the description. Missing or vacuous answers ("developers benefit from cleaner code") are a
  Must fix; the author agent should close the PR rather than argue for it.
- A correct, well-tested change to code nobody should be investing in is still a decline — the
  review pipeline's correctness checks below do not substitute for this question.

### Code quality
- PHPStan compliance — no type errors, missing return types, or unsafe casts
- Laravel conventions — Eloquent over raw queries, Form Requests for validation, named routes
- No `env()` calls outside config files
- No N+1 query risks (eager loading where needed)
- Constructor property promotion used; no empty constructors
- Explicit return types on all methods

### Project conventions
- Follows existing directory structure (no new base folders without approval)
- Enum keys are TitleCase
- No inline comments except for exceptionally complex logic (PHPDoc preferred)
- Uses existing services and helpers — no duplicate logic
- Correct use of `Image::read()` (not `Image::make()`) for Intervention Image v1.x

### Frontend (if any Blade/Livewire/CSS changes)
- Uses design system components (`x-button`, `x-card`, `x-input`, etc.) — no hand-rolled equivalents
- Internal links use `wire:navigate`; external links use plain `<a>`
- Only `cbc-*` palette colors for brand elements — no arbitrary colors
- No indigo focus chains introduced
- Loading, empty, error, and success states present
- `aria-label` on icon-only actions; keyboard-operable controls
- `wire:navigate` on all internal links

### Tests
- Every changed feature or bug fix has accompanying tests
- Tests cover happy path, failure paths, and edge cases
- No tests removed without good reason
- PHPUnit classes (not Pest)

### Security
- No command injection, XSS, SQL injection, or other OWASP top-10 risks
- User input validated at system boundaries
- No secrets or credentials in code

## 4. Summarise findings

Structure your output as:

**PR #{number} — {title}**

| | |
|---|---|
| Author | {author} |
| Branch | `{branch}` → `master` |
| CI | {passing/failing/pending} |

### Must fix
- Blocking issues that should prevent merge (bugs, security, broken tests, PHPStan errors)
- Necessity failures: touches the do-not-invest list, or an autonomous PR without a credible
  who-benefits / what-improves statement (recommend closing, not fixing)

### Should fix
- Convention violations, missing test coverage, code quality issues

### Nice to have
- Non-blocking suggestions — style, clarity, minor improvements

### Looks good
- Positive notes on things done well

## 5. Offer next steps

Ask the user whether they want you to:
- Fix any of the identified issues directly
- Run the full quality check suite locally (`pint`, `phpstan`, `test --parallel`, `dusk`)
- Approve/request changes via `gh pr review`
