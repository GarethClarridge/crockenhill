# Integration Tests

Tests in this directory exercise a single application service (or a small cluster of collaborators) end-to-end through the database or filesystem. They boot Laravel, use factories or `RefreshDatabase`, but do **not** drive HTTP, Livewire, or Console entrypoints — those belong in `tests/Feature/`.

**The rule in one sentence:** if a test needs a real database but never makes an HTTP request or mounts a Livewire component, it belongs here.

## Suite boundaries

| Directory | What belongs there |
|-----------|-------------------|
| `tests/Unit/` | Pure collaborator-level tests — one class, mocked or hand-built dependencies, no DB, no factories, no storage |
| `tests/Integration/` | Service-level tests that need the database or filesystem but not HTTP or Livewire |
| `tests/Feature/` | HTTP, Livewire, Console, and full end-to-end tests |

## Running the integration suite

```bash
vendor/bin/sail artisan test --testsuite="Integration Tests"
```

## Bulk migration in progress

The ~108 remaining DB-backed tests under `tests/Unit/Services/` should move here over time. This is a deliberate follow-up to keep that migration reviewable as a no-semantic-change PR. New tests should land in the correct directory from the start.
