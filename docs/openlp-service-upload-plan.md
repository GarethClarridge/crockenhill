# Plan: OpenLP Service File Upload & Parsing

## Context

OpenLP `.osz` files are ZIP archives containing a `.osj` JSON file that describes a church service order — songs, Bible readings, presentations, and custom items in sequence. The goal is a web endpoint that accepts `.osz` uploads, parses the order of service, stores it to the database, and returns a structured response. This enables automated ingestion of Sunday services from OpenLP into the website.

---

## New Files

| File | Purpose |
|------|---------|
| `app/Models/ChurchService.php` | Parent model — one per service day/slot |
| `app/Models/ChurchServiceItem.php` | Individual items within a service |
| `app/Http/Controllers/Api/ChurchServiceController.php` | API controller |
| `app/Http/Requests/UploadChurchServiceRequest.php` | Validates .osz file upload |
| `app/Services/OpenLpServiceParser.php` | Parses .osz → structured array |
| `app/Http/Resources/ChurchServiceResource.php` | API response shape |
| `database/factories/ChurchServiceFactory.php` | Test factory |
| `database/factories/ChurchServiceItemFactory.php` | Test factory |
| `tests/Feature/ChurchServiceApiTest.php` | Feature tests |

---

## Modified Files

| File | Change |
|------|--------|
| `app/Enums/ApiTokenAbility.php` | Add `ServiceUpload = 'service:upload'` case |
| `database/migrations/` | Two new migrations (see below) |
| `routes/api.php` | Add `POST /api/services` route |

---

## Implementation Steps

### 1. Migrations

**`create_church_services_table`**
```
id, date (date), service (string, nullable — SermonService enum value),
original_filename (string), timestamps
```

**`create_church_service_items_table`**
```
id, church_service_id (FK → cascade delete), position (unsignedInteger),
type (string — songs/presentations/bibles/custom),
title (string), timestamps
index on (church_service_id, position)
```

### 2. Models

**`ChurchService`**
- Casts: `date` → `'date'`, `service` → `SermonService::class` (nullable)
- Relationship: `hasMany(ChurchServiceItem)` ordered by `position`
- Fillable: `date`, `service`, `original_filename`

**`ChurchServiceItem`**
- Fillable: `church_service_id`, `position`, `type`, `title`
- Relationship: `belongsTo(ChurchService)`

### 3. `OpenLpServiceParser` Service

```php
public function parse(UploadedFile $file): array
```

Steps:
1. Open the .osz file as a ZipArchive
2. Find the `.osj` file entry (iterate entries, match extension)
3. `json_decode()` the contents
4. Skip the `openlp_core` config entry
5. For each `serviceitem`, extract: `header.name` (type), `header.title`
6. Return `['date' => ..., 'service' => ..., 'items' => [...]]`

Date/service derived from the filename (`2024-11-17 PM.osz` → date=`2024-11-17`, service=`evening`; `AM` → `morning`).

Item types mapped: `songs`, `presentations`, `bibles`, `custom` → stored as-is (already clean strings from OpenLP).

### 4. `UploadChurchServiceRequest`

- `authorize()`: `$this->user()->is_admin`
- `rules()`: `['file' => ['required', 'file', 'mimes:zip,osz', 'max:102400']]`
  - Note: `.osz` is a ZIP, so `mimes:zip` covers it; allow up to 100MB for embedded pptx
- Custom message for unsupported file type

### 5. `ChurchServiceController`

```php
public function store(UploadChurchServiceRequest $request): JsonResponse
{
    $parsed = $this->parser->parse($request->file('file'));

    $service = ChurchService::create([...]);
    $service->items()->createMany($parsed['items']);

    return (new ChurchServiceResource($service->load('items')))
        ->response()
        ->setStatusCode(201);
}
```

### 6. `ChurchServiceResource`

```json
{
    "id": 1,
    "date": "2024-11-17",
    "service": "morning",
    "original_filename": "2024-11-17 AM.osz",
    "items": [
        {"position": 1, "type": "songs",         "title": "Jesus Shall Reign #491(i)"},
        {"position": 2, "type": "presentations", "title": "RalphNov2024.pptx"},
        {"position": 3, "type": "bibles",        "title": "Luke 15:1-32 NIV"}
    ]
}
```

### 7. Route

```php
// routes/api.php
Route::post('services', [ChurchServiceController::class, 'store'])
    ->middleware([
        'auth:sanctum',
        'ability:' . ApiTokenAbility::ServiceUpload->value,
        'throttle:api',
    ])
    ->name('api.services.store');
```

### 8. `ApiTokenAbility` Enum

Add: `case ServiceUpload = 'service:upload';`

---

## Verification

1. Run migrations: `vendor/bin/sail artisan migrate`
2. Run feature tests: `vendor/bin/sail artisan test --compact tests/Feature/ChurchServiceApiTest.php`
3. Tests cover:
   - Happy path: valid AM and PM `.osz` files parsed and stored correctly
   - Returns 201 with correct JSON structure
   - Returns 401 without authentication
   - Returns 422 for non-.osz files
   - Items are stored in correct order with correct types/titles
4. Run full suite: `vendor/bin/sail artisan test --parallel --compact`
5. PHPStan: `vendor/bin/sail composer phpstan`
6. Pint: `vendor/bin/sail bin pint --dirty`
