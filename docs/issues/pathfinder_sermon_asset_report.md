# 🔗 Pathfinder: Broken Sermon Asset on Seeded Data

- **Summary:** The "The Prodigal Son" sermon record, created by `SermonSeeder`, references an audio file that does not exist in the environment's storage. It also has a mismatch between the `MediaProcessingLog` and the `Sermon` record itself.
- **Affected items:**
  - Sermon: `the-prodigal-son` (Slug: `the-prodigal-son`)
  - Audio Path: `sermons/seed/2024-11-24.mp3`
  - Processing ID: `seed-prodigal-son-processing`
- **Verification:**
  - `Sermon` record exists but `audio_file_path` is `null`.
  - `MediaProcessingLog` exists with `audio_file_path = "sermons/seed/2024-11-24.mp3"` and `status = "completed"`.
  - Command `Storage::disk('public')->exists('sermons/seed/2024-11-24.mp3')` returns `false`.
  - The sermon detail page renders without an audio player, providing a "broken promise" to visitors.
- **Likely cause:** `SermonSeeder` assumes the presence of a seed audio file that is not bundled with the repository or generated during the environment setup (`jules-setup.sh`). Additionally, the seeder creates the processing log but fails to update the `Sermon` record's `audio_file_path`.
- **Suggested action:**
  1. Update `SermonSeeder` to set the `audio_file_path` on the `Sermon` record.
  2. Provide a small silent or dummy `.mp3` file at `storage/app/public/sermons/seed/2024-11-24.mp3` during the setup process, or update the seeder to use an existing asset if available.
  3. Alternatively, mark the seeded log as `failed` to reflect the actual state of the asset.
- **Risk note:** This is a developer-experience issue that leads to confusing UI states in development and testing environments. It does not appear to affect production sermons which follow a different processing pipeline.

---

## Secondary Finding: Systemic Heading Image Resolution Gap (O13)

- **Summary:** Most public pages (e.g., History, Pastor, Find Us) are missing their heading images in `sitemap.xml` and on-page hero sections, despite assets existing in `public/images/headings/`.
- **Affected items:** 20+ public pages.
- **Likely cause:** `PageImageCacheService` only checks Spatie Media Library and `Storage::disk('public')` (mapping to `storage/app/public/pages/headings/`), but committed assets are in `public/images/headings/`.
- **Suggested action:** Update `PageImageCacheService::resolveHeadingImageUrl` to include a fallback check for `public_path('images/headings/...')`.
