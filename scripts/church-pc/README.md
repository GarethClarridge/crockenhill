# Church PC automation scripts

Client-side half of the Phase C automations in
[`docs/reports/service-automation-opportunities-2026-07-05.md`](../../docs/reports/service-automation-opportunities-2026-07-05.md).
These scripts are **versioned here** (next to the API endpoints they consume) but
**deployed by copying this folder** onto the church PC — the church PC must never
hold a checkout of, or credentials for, this repository.

| Script | Report item | Purpose |
|---|---|---|
| `Upload-Recording.ps1` | C1 | Watch the OBS output folder; upload finished recordings to `POST /api/media/livestream`; confirm processing started. |
| `Upload-ServiceFile.ps1` | C4 | Watch OpenLP's service-file folder; upload saved `.osz` files to `POST /api/services/openlp` so live edits flow back to the canonical service record. |
| `ChurchPcCommon.psm1` | — | Shared helpers (logging, token storage, upload state, stability checks, curl upload). Not run directly. |

If a script fails, the manual workflow is unchanged: use the admin upload form.

## One-time installation on the church PC

1. **Install PowerShell 7** (the scripts require it; Windows' built-in 5.1 is not supported):

   ```
   winget install Microsoft.PowerShell
   ```

2. **Copy this folder** somewhere stable, e.g. `C:\ChurchAutomation\`.

3. **Create the config**: copy `config.example.json` to `config.json` in the same
   folder and set:
   - `base_url` — the production site URL.
   - `recordings.watch_folder` — OBS's recording output folder (OBS → Settings → Output → Recording Path).
   - `service_files.watch_folder` — the folder where the operator saves the OpenLP service (`.osz`) file.
   - Leave the rest at their defaults unless you have a reason not to.

   In OBS, prefer recording to **mkv with "Automatically remux to mp4"** enabled —
   the recording script prefers the remuxed `.mp4` and skips its `.mkv` sibling.

4. **Mint a least-privilege API token** (on the server / from a dev machine).
   The token's user must be an admin. Both scripts share one token, so grant
   both abilities. Note the ability strings use colons — `media.process` and
   `service.access` are middleware aliases, not abilities:

   ```
   php artisan api:create-token <admin-email> "Church PC uploader" --abilities=media:process --abilities=service:upload
   ```

   (Locally that is `vendor/bin/sail artisan api:create-token ...`.)

5. **Store the token on the church PC** (DPAPI-encrypted, bound to this machine
   and Windows account — a copied token file is useless elsewhere). Run as the
   Windows account that will run the scheduled tasks:

   ```
   pwsh -File C:\ChurchAutomation\Upload-Recording.ps1 -StoreToken
   ```

   (Either script can store it; they read the same file.)

6. **Smoke-test manually** before scheduling:

   ```
   pwsh -File C:\ChurchAutomation\Upload-Recording.ps1 -DryRun
   pwsh -File C:\ChurchAutomation\Upload-Recording.ps1
   pwsh -File C:\ChurchAutomation\Upload-ServiceFile.ps1 -DryRun
   pwsh -File C:\ChurchAutomation\Upload-ServiceFile.ps1
   ```

7. **Register the scheduled tasks** — every 15 minutes on Sunday afternoons
   (12:00–17:00), so uploads start shortly after OBS/OpenLP finish writing:

   ```
   schtasks /Create /TN "Crockenhill\Upload Recording" ^
     /TR "pwsh -NoProfile -File C:\ChurchAutomation\Upload-Recording.ps1" ^
     /SC WEEKLY /D SUN /ST 12:00 /RI 15 /DU 05:00 /F

   schtasks /Create /TN "Crockenhill\Upload Service File" ^
     /TR "pwsh -NoProfile -File C:\ChurchAutomation\Upload-ServiceFile.ps1" ^
     /SC WEEKLY /D SUN /ST 12:00 /RI 15 /DU 05:00 /F
   ```

   Run them as the same account used in step 5 (DPAPI decryption is per-account).

## How the scripts decide what to upload

Common gates (both scripts):

- Only files in the watch folder modified within the last `max_age_hours`
  (so an old backlog is never mass-uploaded on install).
- A file must be *finished*: last modified more than `min_age_minutes` ago, size
  unchanged across a `stability_check_seconds` re-check, and not locked by the
  writing application.
- Uploads use `curl` (bundled with Windows) so multi-GB files stream from disk.

Recording-specific (`Upload-Recording.ps1`):

- Extensions from `recordings.extensions`; an `.mkv` with a same-named `.mp4`
  sibling is skipped in favour of the remux.
- Deduplicated **by filename** in `recording-upload-state.json` — a recording is
  never sent twice. Livestream uploads are rate-limited server-side to 1/minute
  and 5/hour per user; the retry delay (90 s) waits out that window.

Service-file-specific (`Upload-ServiceFile.ps1`):

- `.osz` only. Deduplicated by **filename + last-write time + size**, because
  operators re-save the same filename week after week — a re-saved file is
  uploaded again, which is safe: the server-side import merges into the
  canonical service record and is idempotent.

## Troubleshooting

- Each script logs to its own file beside it (`upload-recording.log`,
  `upload-service-file.log`).
- `403 Missing required token ability` — the token lacks `media:process`
  (recordings) or `service:upload` (service files), or was minted with the
  dot-form middleware alias by mistake.
- `403 Unauthorized action` — the token's user is not an admin.
- `404` from the service-file upload — service tracking is disabled server-side
  (`service-tracking.enabled`).
- Recording accepted but no progress — check the admin media dashboard; the
  `processing_id` for every upload is in the log and in
  `recording-upload-state.json`.
- To make a script forget a file (e.g. to re-upload after a server-side
  cancel), remove its entry from the relevant `*-upload-state.json`.
