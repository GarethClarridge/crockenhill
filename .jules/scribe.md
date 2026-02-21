## 2026-02-27 - [Sitemap Media Testing]
**Learning:** Testing `Spatie\Sitemap` integration requires understanding the internal property names of `Video` and `Image` tags. For example, `Video` uses `thumbnailLoc` and `contentLoc` instead of `thumbnailUrl` and `contentUrl`.
**Action:** Use `var_dump` or reflection on third-party tag objects to verify correct property assertions.

## 2026-02-27 - [Testing without Database]
**Learning:** In environments where the primary database (MySQL) is unavailable and migrations fail on alternatives (SQLite), unit tests for model methods can still be performed using `Model::factory()->make()`.
**Action:** Prefer `make()` over `create()` for unit tests that only verify data transformation logic and do not require persistence. Also, remove the `DatabaseTransactions` trait from such tests as it still attempts to establish a DB connection.
