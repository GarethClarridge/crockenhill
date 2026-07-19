# 🔗 Pathfinder Report: Link & Asset Audit (2026-07-19)

## Title: `🔗 Pathfinder: Broken footer link and missing sermon asset on public pages`

### 1. Incorrect Footer Link for Evening Sermons (Ref: O11)
- **Summary:** The public footer link labeled "Listen to evening sermons" incorrectly points to the unfiltered sermons index page (`/christ/sermons`) instead of the dedicated evening services route (`/christ/sermons/evening`).
- **Affected Surface:** Global public footer component (`resources/views/components/layout/footer.blade.php`).
- **Verification:**
  - File: `resources/views/components/layout/footer.blade.php` at line 15:
    ```html
    <a class="{{ $linkClasses }}" href="/christ/sermons" wire:navigate>
      Listen to evening sermons
    </a>
    ```
  - Expected route: `/christ/sermons/evening`
  - Current route: `/christ/sermons`
- **Likely Cause:** Typo/oversight in the footer template during the layout design or route migration.
- **Suggested Action:** Update the `href` attribute on line 15 of `resources/views/components/layout/footer.blade.php` to `/christ/sermons/evening`.
- **Risk Note:** Low. The target route is active and functional; fixing the link improves visitor navigation and SEO structure for the evening services collection.

---

### 2. Missing Seeded Sermon Audio for "The Prodigal Son" (Ref: O12)
- **Summary:** The flagship seeded sermon "The Prodigal Son" renders an audio player on its details page, but the underlying media asset does not exist on disk, resulting in a broken player for local development and seeding environments.
- **Affected Surface:** `/christ/sermons/2024/11/the-prodigal-son`
- **Verification:**
  - Expected storage path: `storage/app/public/sermons/seed/2024-11-24.mp3`
  - Check result: Directory `storage/app/public/sermons/seed/` and file `2024-11-24.mp3` do not exist.
  - Database state: `MediaProcessingLog` contains entry with `processing_id = 'seed-prodigal-son-processing'` and status `completed`, pointing to `audio_file_path = 'sermons/seed/2024-11-24.mp3'`.
- **Likely Cause:** The seeder references a file `sermons/seed/2024-11-24.mp3` that is not bundled with the repository or generated during project initialization.
- **Suggested Action:** Provide a lightweight placeholder audio file at `storage/app/public/sermons/seed/2024-11-24.mp3` during database seeding, or update the seeder to construct a valid placeholder stream.
- **Risk Note:** Medium. While this is primarily a development/seeding issue, any similar mismatch in production (where a processing log is marked completed but the underlying storage file is absent) would present a broken experience to public users.
