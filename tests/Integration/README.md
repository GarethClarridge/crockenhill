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

## Migration completed

The bulk migration of DB-backed tests out of `tests/Unit/` is complete: `tests/Unit/` now contains only collaborator-level tests with no `RefreshDatabase`, `DatabaseTransactions`, or `DatabaseMigrations` usage. New tests must land in the correct directory from the start — DB-backed service and model tests go here, HTTP/Livewire/Console tests go in `tests/Feature/`.
