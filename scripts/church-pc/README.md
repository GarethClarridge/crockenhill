# Church PC automation scripts

Client-side half of the Phase C automations in
[`docs/reports/service-automation-opportunities-2026-07-05.md`](../../docs/reports/service-automation-opportunities-2026-07-05.md).
These scripts are **versioned here** (next to the API endpoints they consume) but
**deployed by copying this folder** onto the church PC — the church PC must never
hold a checkout of, or credentials for, this repository.

| Script | Report item | Purpose |
|---|---|---|
| `Upload-Recording.ps1` | C1 | Watch the OBS output folder; upload finished recordings to `POST /api/media/livestream`; confirm processing started. |

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
   - `watch_folder` — OBS's recording output folder (OBS → Settings → Output → Recording Path).
   - Leave the rest at their defaults unless you have a reason not to.

   In OBS, prefer recording to **mkv with "Automatically remux to mp4"** enabled —
   the script prefers the remuxed `.mp4` and skips its `.mkv` sibling.

4. **Mint a least-privilege API token** (on the server / from a dev machine).
   The token's user must be an admin, and the ability string is `media:process`
   (colon, not dot — the dot form is the middleware alias, not the ability):

   ```
   php artisan api:create-token <admin-email> "Church PC uploader" --abilities=media:process
   ```

   (Locally that is `vendor/bin/sail artisan api:create-token ...`.)

5. **Store the token on the church PC** (DPAPI-encrypted, bound to this machine
   and Windows account — a copied token file is useless elsewhere). Run as the
   Windows account that will run the scheduled task:

   ```
   pwsh -File C:\ChurchAutomation\Upload-Recording.ps1 -StoreToken
   ```

6. **Smoke-test manually** before scheduling:

   ```
   pwsh -File C:\ChurchAutomation\Upload-Recording.ps1 -DryRun
   pwsh -File C:\ChurchAutomation\Upload-Recording.ps1
   ```

7. **Register the scheduled task** — every 15 minutes on Sunday afternoons
   (12:00–17:00), so the upload starts shortly after OBS finishes writing:

   ```
   schtasks /Create /TN "Crockenhill\Upload Recording" ^
     /TR "pwsh -NoProfile -File C:\ChurchAutomation\Upload-Recording.ps1" ^
     /SC WEEKLY /D SUN /ST 12:00 /RI 15 /DU 05:00 /F
   ```

   Run it as the same account used in step 5 (DPAPI decryption is per-account).

## How Upload-Recording.ps1 decides what to upload

- Only files in `watch_folder` with a configured extension, modified within the
  last `max_age_hours` (so an old backlog is never mass-uploaded on install).
- A file must be *finished*: last modified more than `min_age_minutes` ago, size
  unchanged across a `stability_check_seconds` re-check, and not locked by OBS.
- Each uploaded filename is recorded in `upload-state.json` and never re-sent.
- The upload itself uses `curl` (bundled with Windows) so multi-GB files stream
  from disk. Livestream uploads are rate-limited server-side to 1/minute and
  5/hour per user; the retry delay (90 s) is chosen to wait out that window.

## Troubleshooting

- Everything the script does is logged to `upload-recording.log` beside it.
- `403 Missing required token ability` — the token was minted without
  `media:process`, or with `--abilities=media.process` (wrong separator).
- `403 Unauthorized action` — the token's user is not an admin.
- Upload accepted but no progress — check the admin media dashboard; the
  `processing_id` for every upload is in the log and in `upload-state.json`.
- To make the script forget a file (e.g. to re-upload after a server-side
  cancel), remove its entry from `upload-state.json`.
