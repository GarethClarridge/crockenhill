# Plan: Manual Email Text Upload

## Context

The existing email ingest pipeline only accepts emails via the Mailgun webhook (`POST /api/mailgun/webhook`). This makes testing difficult — to exercise the email parser you currently need to actually send an email through Mailgun. Admins also sometimes receive order-of-service emails on personal devices and want to paste the content in directly rather than forwarding it.

This plan adds a simple admin UI form that accepts raw email text, creates an `InboundEmail` record with a synthetic message ID, and queues it for processing via the same `ProcessInboundOosEmail` job that the webhook uses. No new backend logic is needed — only the entry point changes.

> **Year-inference caveat**: `OosEmailParserService` uses `received_at` to infer missing years in service dates. Manual submissions stamp `received_at = now()`, so pasted historic emails (e.g. from a previous year) may parse to the wrong service date if the email body omits the year. Admins should verify the resolved date in the review list before approving.

---

## Scope

Small. One new Livewire component, one Blade view, one route, minor navigation additions.

---

## New Files

| File | Purpose |
|------|---------|
| `app/Livewire/Admin/ChurchServices/SubmitEmailText.php` | Livewire component — accepts form input, creates InboundEmail, dispatches job |
| `resources/views/livewire/admin/church-services/submit-email-text.blade.php` | Form view |

---

## Modified Files

| File | Change |
|------|--------|
| `routes/web.php` | Add `GET /admin/services/submit-email` route |
| `resources/views/livewire/admin/church-services/review-inbound-emails.blade.php` | Add nav button linking to the new form |
| `resources/views/livewire/admin/church-services/upload-church-service.blade.php` | Add nav button linking to the new form |
| `tests/Feature/ChurchServices/SubmitEmailTextTest.php` | Feature tests |

---

## Implementation Steps

### 1. Route

Add to the `isAdmin` web route group alongside the existing inbound email route:

```php
Route::get('services/submit-email', SubmitEmailText::class)
    ->name('admin.services.submit-email');
```

### 2. `SubmitEmailText` Livewire Component

**Properties**:
- `string $from = ''` — optional sender (for display in review list)
- `string $subject = ''` — optional subject line
- `string $bodyPlain = ''` — required, the raw email text
- `bool $submitted = false` — switches the view to a confirmation state

**Validation rules**:
```php
[
    'from'      => ['nullable', 'string', 'max:255'],
    'subject'   => ['nullable', 'string', 'max:255'],
    'bodyPlain' => ['required', 'string', 'min:20', 'max:50000'],
]
```

**`mount()` hook**: Call `authorizeAdmin()` then `abortIfDisabled()` — same gate used by `UploadChurchService` and `ReviewInboundEmails`.

**`submit()` action**:
1. Validate
2. Generate a synthetic message ID: `'manual-' . Str::uuid() . '@admin.crockenhill.org'`
3. Create `InboundEmail`:
   ```php
   InboundEmail::create([
       'message_id'  => $syntheticId,
       'from'        => $this->from ?: 'admin@manual-entry',
       'subject'     => $this->subject ?: 'Manual entry',
       'body_plain'  => $this->bodyPlain,
       'body_html'   => null,
       'received_at' => now(),
       'status'      => InboundEmailStatus::PENDING,
   ]);
   ```
4. Dispatch `ProcessInboundOosEmail::dispatch($inboundEmail)`
5. Set `$this->submitted = true`

**After submission** — show a confirmation panel with a link to the inbound email review page (`admin.services.inbound-emails`), where the admin can immediately see the parse result and approve/reject it.

### 3. Form View

A simple two-column form card:

- Optional fields: From (text input), Subject (text input) — collapsed or de-emphasised since most admins will leave them blank
- Required field: Body (large `<textarea>`, ~20 rows, monospace font) — the paste target
- Submit button: "Submit for parsing"
- After submission: inline success panel — "Email submitted. Confidence: [shown after refresh] → [link to review]"

Use the project's shared `<x-input>` and `<x-textarea>` components (not raw `<input>`/`<textarea>` elements) — they handle labels, error display, and focus ring styling consistently. The view should match the style of `upload-church-service.blade.php` — a centred, single-card layout.

### 4. Navigation

Add a "Submit Email Text" button to:
- The header of `review-inbound-emails.blade.php`
- The header of `upload-church-service.blade.php`

---

## Testing

**Happy path**:
- POST with valid body text → `InboundEmail` created with `status = PENDING`, job dispatched
- Confirmation state shown after submission

**Validation**:
- Empty body → validation error shown inline
- Body under 20 characters → rejected (too short to be a valid OoS)

**Integration**:
- Submitted email flows through `ProcessInboundOosEmail` → appears in the review list with a parsed preview

---

## Out of Scope

- HTML email body support — plain text only; real Mailgun emails already carry both, but for a paste-in form plain text is sufficient
- Attachment handling — not needed; emails with `.osz` attachments are not a supported path

---

## Verification Checklist

1. `vendor/bin/sail artisan test --compact tests/Feature/ChurchServices/SubmitEmailTextTest.php`
2. `vendor/bin/sail bin pint --dirty`
3. `vendor/bin/sail composer phpstan`
4. Manually: paste a known-good OoS email body → confirm it appears in the review list with a parsed preview
