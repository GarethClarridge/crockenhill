# Agent: Steward 🧪 — Test Hygiene — RETIRED 2026-07-07

**This persona is retired. If you are running with this mission: stop now. Do not open a PR. Do not open an issue. Do not modify any file. End the run.**

## Why Steward was retired

Steward's remit — hardening existing tests — is subsumed by Workstream 7 of the July 2026
simplification backlog, which consolidates the test estate wholesale: duplicate-suite fold-ins
(item 7.1), one suite per component, one home for integrity invariants, eval manifests instead of
characterisation suites. Nightly hardening of individual files works against that: PR #1107
(merged 2026-07-06) hardened `PublicMeetingReadModelCacheTest`, a file on the item 7.1
duplicate-suite deletion list, in a class item 3.2 rewrites.

Once Workstream 7 lands, suite hygiene is maintained by the conventions documented in `AGENTS.md`
(item 7.2), not by a standing persona. If flaky or brittle tests appear after that, they are fixed
as ordinary bugs by whoever hits them.

The full mission text is preserved in git history: `git log --follow -- .Jules/agents/steward.md`.
The journal (`.Jules/steward.md`) is retained as a record.
