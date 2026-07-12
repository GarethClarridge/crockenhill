# Agent: Herald 📜 — Inline Documentation & DX — RETIRED 2026-07-07

**This persona is retired. If you are running with this mission: stop now. Do not open a PR. Do not open an issue. Do not modify any file. End the run.**

## Why Herald was retired

A nightly PHPDoc-adding agent accumulates documentation ballast that ages badly: comments drift
from the code they describe, and every block added to code the July 2026 simplification backlog
deletes (~20,000+ lines scheduled) is review effort spent twice. Recent runs were documenting
services on or near the deletion list.

The documentation work that actually moves the needle is keeping `AGENTS.md` accurate — the
platform-operations review (2026-07-05, F8) found its Key Services section listed three services
that no longer exist and called fixing it "the highest-leverage doc fix in the repo". That is a
human-owned task (backlog item 6.5), not a nightly persona.

PHPDoc for new code is written with the code, by whoever writes it, per the project conventions
in `AGENTS.md`.

The full mission text is preserved in git history: `git log --follow -- .Jules/agents/herald.md`.
The journal (`.Jules/herald.md`) is retained as a record.
