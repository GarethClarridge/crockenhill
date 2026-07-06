# Service Recording & Livestream Automation — Opportunities Report

**Date**: 2026-07-05
**Status**: Ideas / discussion document (no commitments)
**Context**: Weekly workflow review — how much of the Sunday service operation can be automated, either in this application or on the church computer.

---

## 1. The current weekly workflow

| When | Step | How it works today | Automation status |
|---|---|---|---|
| Mid-week | Order of service emailed | Human reads email | ❌ Manual (ingestion pipeline built but dormant) |
| Sunday pre-service | OpenLP order of service created | Manually assembled from the email | ❌ Manual |
| Sunday pre-service | YouTube livestream created | Manually in YouTube Studio; OBS uses persistent stream key | ❌ Manual |
| During service | Livestream + projection | OBS + OpenLP, with OpenLP→OBS content automation | ✅ Partially automated |
| During service | Recording | OBS records while streaming | ✅ (but relies on remembering to start) |
| After service | Upload recording + OoS | Operator uploads from church computer via admin form | ❌ Manual, operator waits around |
| After upload | Segmentation, sermon extraction, transcription, publication | Full pipeline | ✅ Automated (with review queue) |

**Operational facts this report assumes** (confirmed 2026-07-05):

- Mailgun inbound routing has **never been configured** — that is the only reason email ingestion is unused.
- OBS uses the channel's **persistent stream key**; broadcasts are created manually in YouTube Studio.
- The church computer is **Windows, has internet, and we may install scripts/scheduled tasks/OBS plugins**.
- The operator uploads the recording **from the church computer straight after the service** and has to wait for it.

## 2. What the codebase already provides

The striking thing is how much of the "hard part" already exists. The gap is almost entirely at the edges — getting data *in* mid-week and getting derived artifacts *out* to OpenLP and YouTube.

- **Email ingestion is complete, end to end**: `POST /api/webhooks/mailgun/inbound` with signature verification (`EnsureValidMailgunWebhookSignature`), Message-Id dedup, durable `InboundEmail` storage, AI parsing (`OosEmailParserService`, `gpt-4.1-nano`) with confidence policy (≥0.90 auto-import, 0.75–0.89 import + review, <0.75 hold for manual review), a review inbox with re-parse/approve/reject, and merge-safe import into the canonical `ChurchService`. There is also a manual paste fallback (`SubmitEmailText`).
- **OpenLP song knowledge**: the site syncs the OpenLP songs SQLite database (`OPENLP_SONGS_DB_PATH`, `SongCatalogSyncService`, `OpenLpLyricsParser`), so it knows OpenLP's exact titles (`openlp_search_title`) and lyrics for every song in the projection library.
- **OpenLP `.osz` import** (`OpenLpServiceParser`): the format is just a zip containing a `.osj` JSON file — which also means we can *generate* one.
- **A token-authenticated machine API**: `POST /api/media` (`auth:sanctum` + `media.process` ability) accepts a direct multipart file upload with `type`, plus status/stream/retry/cancel endpoints. A script on the church computer can use this today.
- **Operational plumbing**: Horizon, laravel-health checks, scheduled tasks, SSE progress streaming, and a consolidated review dashboard.

## 3. The organising idea: make the website the hub

Once the mid-week email lands in the application, the canonical `ChurchService` record exists **days before Sunday**. Everything else can then be *derived* from it, in both directions:

```
                        Mid-week email
                              │
                              ▼ (Mailgun inbound — activate, no code)
                     ChurchService (canonical)
                              │
        ┌─────────────────────┼──────────────────────┐
        ▼                     ▼                      ▼
  OpenLP service        YouTube broadcast      Readiness checks
  (generated .osz or    (API-scheduled,        (songs missing from
  script driving        bound to persistent    OpenLP? broadcast
  OpenLP's local API)   stream key)            created? alerts)
        │                     │
        └───────── Sunday ────┘
                              │
                              ▼ (watch-folder agent on church PC)
                    Recording auto-uploaded
                              │
                              ▼ (existing pipeline)
              Sermon, sections, transcripts, songs
                              │
                              ▼
              YouTube metadata backfill (chapters,
              description, thumbnail), CCLI export
```

The rest of this report works through that diagram as concrete proposals, grouped by where they run and roughly ordered by value-per-effort.

---

## 4. Phase A — Activation only, no code required

### A1. Turn on email ingestion (the single best next step)

Everything is built. The work is configuration:

1. Create a Mailgun **inbound route** for a dedicated address (e.g. `oos@crockenhill.org`) that forwards to `https://<prod>/api/webhooks/mailgun/inbound`. (Requires the domain's MX records to point at Mailgun for that subdomain/address, or a receiving route on the existing Mailgun domain.)
2. Set `MAILGUN_SIGNING_KEY` in production if not already present (`config/service-tracking.php` reads it).
3. Ask the person who sends the mid-week email to **add the new address as a recipient** (To or CC). If that's socially awkward, an inbox-side auto-forward rule from whoever already receives it works identically — the parser doesn't care about `From`.
4. For the first month, treat every import as review-required in practice: check the review inbox after each email, use the **re-parse** action when the parser misses something, and collect the real emails as a test corpus.

**Failure modes and their existing mitigations**: a garbled parse never silently corrupts the canonical list (<0.75 confidence holds the payload for review); a duplicate/resent email is deduplicated by Message-Id; a forwarded email with quoting noise can be re-parsed after prompt tuning because the raw body is stored durably.

**Effort**: an hour or two of DNS/Mailgun config plus one prompt-tuning iteration against real emails.

### A2. Zero-code YouTube baseline: recurring broadcasts

Before building anything, note that YouTube Studio supports **recurring scheduled livestreams** on a persistent stream key. Setting up "every Sunday 10:30" gives an auto-created broadcast each week with a generic title. This removes the Sunday-morning creation step immediately; the API work in B2 then becomes about *enriching* (correct sermon title, description, thumbnail) rather than *creating*. Worth doing this week regardless.

---

## 5. Phase B — Mid-week automation in this application

### B1. Generate the OpenLP order of service automatically

This is the biggest weekly time-saver on offer: Sunday-morning OpenLP assembly drops from "rebuild the whole service from an email" to "load a file / press a button, then fix anything odd".

Two viable designs — recommend starting with (b):

**(a) Server-generated `.osz` download.**
The application emits a `.osz` (zip containing an `.osj` JSON array) from the canonical `ChurchService`: song items built from stored lyrics (rebuilding OpenLyrics XML via the data the sync already holds), readings/notices as custom slides. Endpoint like `GET /admin/services/{service}/openlp.osz` (plus a signed-URL variant for the fetch script in C2).
*Risk*: the `.osj` service-item format is an OpenLP internal serialization, not a documented interchange format. It embeds rendered slide data and version-sensitive fields; a generator must be validated against the church's exact OpenLP version and re-checked on OpenLP upgrades. Feasible — the import parser proves the format is plain JSON — but this is the fragile option.

**(b) A local script that drives OpenLP's own API.** *(Recommended)*
OpenLP 3.x ships a web remote / HTTP API on the machine it runs on, able to search the local songs database and add items to the live service. A small script on the church computer:
1. `GET /api/church-services/next` (new, tiny, read-only endpoint returning the canonical item list with `openlp_search_title` for songs),
2. asks local OpenLP to search each song by that exact title and append it, adding readings/headings as custom slides,
3. leaves the operator with an assembled service to eyeball.

This sidesteps the serialization risk entirely: songs are linked against the *local* database by OpenLP itself, so themes, formatting, and verse order all behave natively. And because the site's song catalog is synced *from* that same database, `openlp_search_title` should hit exact matches. The new website endpoint is trivial; the intelligence lives in a ~100-line script (see C2).

**Prerequisite for either**: the song-catalog sync must be reasonably fresh so mid-week emails match. B3 below closes that loop.

### B2. Auto-create and enrich the YouTube broadcast

A weekly scheduled command (Laravel scheduler already runs in `bootstrap/app.php`):

- After the OoS email imports (or a Thursday-evening fallback if none arrived), call `liveBroadcasts.insert` for Sunday's service time and `liveBroadcasts.bind` it to the channel's **persistent live stream** — the one whose key OBS already has. OBS needs no changes at all; when it starts streaming, YouTube routes the feed to the bound broadcast.
- Title/description from the canonical service: date, sermon title, preacher, passage (the email parser extracts these).
- Store the broadcast ID on `ChurchService` (new nullable column) — this is what later lets D1 write chapters back and D3 check "are we actually live?".
- If A2's recurring broadcast is in place, this command *updates* the auto-created broadcast instead of inserting.

**Plumbing**: one-time Google OAuth consent for the channel (offline access, refresh token stored in config/secret storage), `https://www.googleapis.com/auth/youtube` scope. Quota is a non-issue (default 10,000 units/day; a broadcast insert is ~50). The main operational risk is refresh-token revocation (e.g. Google security events) — pair it with a health check that alerts if the token stops working, rather than discovering it at 10:25 on Sunday.

### B3. Mid-week "songs missing from OpenLP" alert

Small but high-leverage quality win. When an email imports, check each song item against the synced OpenLP catalog. Any song with no confident match means **the lyrics probably aren't in OpenLP yet** — exactly the thing you want to know on Wednesday, not at 10:15 on Sunday. Send the operator a short email: "2 of 5 songs not found in OpenLP: … Add them before Sunday, then re-run sync." Hooks cleanly into the existing import flow and notification mail infrastructure.

### B4. Sunday-readiness dashboard

One admin panel (extend the existing admin dashboard / review inbox area) answering, at a glance, "is Sunday ready?":

- OoS email received and imported? (link to review inbox if held)
- All songs matched to OpenLP catalog? (from B3)
- YouTube broadcast created/bound? (from B2, with link)
- Last week's recording processed and published? Anything in the review queue?

Each row is data the system already has or that B2/B3 create. This also becomes the natural home for the health signals in D3.

---

## 6. Phase C — Automation on the church computer (Windows)

All of these are small scripts installed once, runnable via Task Scheduler, and designed so that **if they fail, the manual workflow still works unchanged**.

### C1. Watch-folder auto-upload of the recording (kills the post-service wait)

A small agent (PowerShell or Python as a scheduled task / tray script) that:

1. Watches OBS's recording output folder.
2. When a new recording file appears **and stops growing** (OBS has finalised it — prefer OBS's `mkv` + auto-remux settings, and wait for the remuxed `mp4`), 
3. uploads it to `POST /api/media` with a dedicated Sanctum token (issued with only the `media.process` ability, revocable independently), `type=livestream`,
4. then polls `GET /api/media/processing/{id}/status` and pops a toast/writes a log line: "Upload complete, processing started."

The operator locks up and goes home; the pipeline emails when review is needed (it already does).

**Things to verify while building**: the production HTTP-body size limit for multi-GB livestream files on this route (the admin form uploads them today, so limits are presumably adequate, but the API path should be load-tested with a real ~2–4 GB file); retry-on-flaky-Wi-Fi behaviour (chunked/resumable upload, or fall back to `rclone` into a staging bucket with a server-side pickup job if plain multipart proves unreliable).

### C2. Sunday-morning OpenLP assembly script

The client half of B1(b): a Task Scheduler job (or desktop shortcut labelled "Build today's service") that pulls the canonical service from the website and assembles it in OpenLP via the local OpenLP API. Combined with B1, Sunday-morning prep becomes: open OpenLP, click the shortcut, review the result.

### C3. OBS start/stop automation ("never forget to press record")

OBS 28+ has **obs-websocket built in**, and the *Advanced Scene Switcher* plugin can act on schedules. Options in increasing ambition:

- **Minimal**: OBS launched by Task Scheduler Sunday 10:00 with `--startreplaybuffer`-style flags — OBS supports `--startrecording` / `--startstreaming` launch flags natively. A 10:20 scheduled launch with `--startstreaming --startrecording` removes the two most-forgotten clicks.
- **Better**: Advanced Scene Switcher rules — start streaming/recording at a scheduled time, stop when a "Service ended" scene is selected or after prolonged silence, and switch to a pre-service slide scene automatically.
- **Guard rail**: a companion check (see D3) that alerts someone's phone if the stream is *not* live by 10:35.

### C4. Auto-upload the OpenLP service file after the service

If the church continues saving the final `.osz` (which reflects any live changes made during the service), the C1 agent can also watch OpenLP's service-file folder and POST the newest `.osz` to the existing church-service upload API. That keeps the OpenLP-over-email song-title precedence working (OpenLP remains the best source for exact titles/catalog links) with zero operator effort. If B1(b) is in place, the round trip becomes fully closed: email → website → OpenLP → (live edits) → website.

---

## 7. Phase D — Post-service enrichment and safety nets

### D1. Write chapters and metadata back to the YouTube video

After the pipeline finishes, the system knows the service structure with timestamps (`ServiceSection`). With the broadcast ID stored by B2, a job can update the completed video:

- **Chapters** in the description (`00:00 Welcome / 03:12 Song: … / 21:40 Sermon: …`) — a large viewer-experience improvement for effectively zero marginal cost, since the section data already exists.
- Description: sermon title, preacher, passage, link to the sermon page on the website.
- Thumbnail: the pipeline already generates sermon thumbnails; `thumbnails.set` can push one.

Gate this on the service having cleared review (or only emit chapters for high-confidence sections) so a misclassified section never publishes a wrong label publicly.

### D2. "Are we live?" health check on Sunday morning

A scheduled check (the app already uses spatie/laravel-health) that runs Sundays at ~10:35: query the bound broadcast's status via the YouTube API; if it isn't `live`, alert (email/notification). This is the safety net that makes the C3 automation trustworthy — automation plus verification, not automation instead of attention.

### D3. Token/integration health checks

Once B2/C1 exist, add health checks for: Google refresh token validity (weekly), church-PC agent heartbeat (the agent pings a tiny endpoint after each run; alert if silent for >8 days), Mailgun inbound (alert if no OoS email ingested by Friday — catches both sender forgetting and routing breakage).

### D4. CCLI reporting export

Song usage is already tracked with confirmed-match semantics. A yearly/termly CSV export formatted for CCLI reporting (title, author, usage count within the reporting window) turns an annual licensing chore into a download. Small, self-contained, admin-only.

### D5. Live subtitles / realtime transcript (existing future plan)

The OBS **LocalVocal** sidecar-transcript idea is already noted as a decoupled future plan (`TranscriptionServiceInterface` is the seam). It belongs on this list because it's the one item that improves the *live* experience (accessibility subtitles on the stream) while also cutting post-service transcription cost — but it's a bigger project and shouldn't queue ahead of Phases A–C.

### D6. Notices digest (already in the backlog)

Phase 11 of the church-service backlog (editorial notice review → subscriber weekly digest) is the natural continuation once ingestion and processing are fully automatic. Listed for completeness; no change to its existing plan.

---

## 8. Suggested order of attack

| # | Item | Where | Effort | Weekly time saved / value |
|---|---|---|---|---|
| 1 | A1 Activate email ingestion | Mailgun + env config | Hours | Unlocks everything below |
| 2 | A2 Recurring YouTube broadcast | YouTube Studio | Minutes | Kills a Sunday-morning step now |
| 3 | B3 Missing-songs alert | App (small) | Small | Prevents Sunday-morning scrambles |
| 4 | C1 Watch-folder auto-upload | Church PC + token | Small–medium | Operator stops waiting after service |
| 5 | B2 YouTube broadcast API job | App + Google OAuth | Medium | Correct titles/desc automatically |
| 6 | B1(b)+C2 OpenLP auto-assembly | App endpoint + PC script | Medium | Biggest prep-time saving |
| 7 | B4 Readiness dashboard | App | Small | Confidence + single glance |
| 8 | C3 OBS auto start/stop + D2 live check | Church PC + app | Small | Removes the worst failure mode |
| 9 | C4 Auto-upload final .osz | Church PC | Small | Closes the song-precedence loop |
| 10 | D1 YouTube chapters backfill | App | Medium | Public-facing quality win |
| 11 | D4 CCLI export | App | Small | Annual chore → download |
| 12 | D5 LocalVocal subtitles | Church PC + app | Large | Live accessibility (separate plan) |

Items 1–4 require almost no new application code and would already transform the week: the email flows in automatically mid-week, missing songs are flagged by Wednesday, the broadcast exists without anyone creating it, and nobody waits around after the service for an upload bar.

## 9. Cross-cutting principles

1. **Every automation keeps its manual fallback.** The paste-email form, the manual admin service editor, the admin upload form, and manual YouTube Studio all stay. A failed script on a Sunday morning must degrade to "do it the old way", never to "no service projection".
2. **The church PC gets a least-privilege token.** One Sanctum token with only the `media.process` ability, stored on that machine, revocable server-side without touching anything else.
3. **Verify what you automate.** Each automation that removes a human step (C1, C3, B2) is paired with a check that alerts a human when the automated step didn't happen (D2, D3).
4. **Review gates stay in charge.** Confidence thresholds and the review inbox already prevent bad parses or misclassifications from silently reaching the public site; new outbound automations (YouTube chapters, OpenLP files) should respect the same gates.
