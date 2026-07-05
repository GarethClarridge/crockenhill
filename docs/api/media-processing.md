# API Reference

Written 2026-07-05 from `routes/api.php` and the controllers it names. **`routes/api.php` is the
source of truth** — if this document and the routes file disagree, the routes file wins and this
document needs updating.

All endpoints are JSON. Authenticated endpoints use a Sanctum bearer token
(`Authorization: Bearer <token>`; create one with `artisan api:create-token {email}`). Media endpoints
additionally require media-processing access (`EnsureMediaProcessingAccess` middleware); service
endpoints require service-tracking access (`EnsureServiceTrackingAccess`).

## Media processing

The primary write API. Used by the church-PC upload scripts and the admin upload form
(`admin.services.upload-recording` drives the same pipeline internally).

### Upload

```
POST /api/media/{type}          type ∈ audio | video | livestream
```

Multipart body: `file` (required). Video uploads may also send `auto_trim` (bool) and
`video_processing_mode` — both are **prohibited** for other types (`ProcessMediaRequest` /
`VideoProcessingOptions::validationRules()`).

Validation limits (from `config/media-processing.php`):

| Type | Max size | Extensions |
|---|---|---|
| `audio` | 100 MB | mp3, wav, m4a, mp4 |
| `video` | 1 GB | mp4, mov, avi, mkv |
| `livestream` | 8 GB (env `MEDIA_PROCESSING_LIVESTREAM_MAX_FILE_SIZE`) | mp4, mov, avi, mkv, webm |

Responses: `202` accepted (body includes `processing_id` and `status_url`), `422` validation or
processing-initiation failure, `400` unknown type. Rate limiter: `media-upload`.

### Processing management

```
GET    /api/media/processing/{processingId}/status            throttle: api
GET    /api/media/processing/{processingId}/stream            throttle: processing-stream
DELETE /api/media/processing/{processingId}                   throttle: api        (cancel)
POST   /api/media/processing/{processingId}/retry             throttle: media-retry
POST   /api/media/processing/{processingId}/confirm-segment   throttle: api
```

- **status** — query params `include_logs` (bool) and `log_limit` (default 20). Returns the
  `StandardProcessingResponse` shape: `found`, `processing_id`, `status`, `current_step`,
  `progress_percentage`, `started_at`, `updated_at`, plus `error_message` / `sermon_id` / log
  entries when applicable. `404` with `found: false` for unknown ids.
- **stream** — Server-Sent Events alternative to polling. Emits a `progress` event whenever the
  status snapshot changes; closes on a terminal status (`completed` / `failed` / `cancelled`) or
  after one hour (`config/media-processing.php` → `sse`).
- **cancel** — `200` on success, `400` if the run cannot be cancelled.
- **retry** — re-runs a failed run; `202` on success, `422` otherwise.
- **confirm-segment** — body `segment_id` (int). Resolves a livestream run parked in manual
  review (status `Failed` + current step `manual_review_required`) by confirming which detected
  segment is the sermon; resumes processing and returns `202`.

## Read-only sermon data

```
GET /api/sermons              public, throttle: api  (SermonApiController@index)
GET /api/sermons/{sermon}     public, throttle: api
```

## Church services (OpenLP)

```
POST /api/services/openlp            .osz order-of-service upload, throttle: media-upload
GET  /api/services/{churchService}   throttle: api
```

## Webhooks

```
POST /api/webhooks/mailgun/inbound   Mailgun signature-verified; throttles: mailgun-probe, mailgun-inbound
```

Inbound-email ingestion for order-of-service emails. Note: as of 2026-07 the Mailgun inbound
route was never configured on the Mailgun side (see
`docs/reports/service-automation-opportunities-2026-07-05.md`).

Rate limiters are defined in `App\Providers\RateLimitServiceProvider`.
