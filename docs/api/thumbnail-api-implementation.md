# Thumbnail API Implementation

This document describes the implementation of thumbnail URLs in API responses and related functionality.

## Overview

The thumbnail generation feature provides comprehensive API integration for automated thumbnail creation, serving, and social media optimization. This implementation includes thumbnail URLs in all API responses, direct thumbnail serving with HTTP caching, and Open Graph meta tags for enhanced social media sharing.

## API Changes

### SermonResource Updates

The `SermonResource` now includes a `thumbnail_url` field in all API responses:

```json
{
  "data": {
    "id": 123,
    "title": "Example Sermon",
    "thumbnail_url": "http://example.com/storage/sermons/thumbnails/example.jpg",
    // ... other fields
  }
}
```

The `thumbnail_url` field is:
- `null` when no thumbnail exists
- A full URL when a thumbnail is available
- Automatically generated using the configured storage disk

### New API Endpoints

#### Sermon Data Endpoints

- `GET /api/sermons` - List sermons with thumbnail URLs
- `GET /api/sermons/{sermon}` - Get individual sermon with thumbnail URL

Query parameters for listing:
- `service` - Filter by service type (morning, evening, other)
- `preacher` - Filter by preacher name
- `series` - Filter by series name
- `with_thumbnail=1` - Only return sermons that have thumbnails

### Processing Status Updates

All processing status endpoints now include thumbnail information when available:

```json
{
  "found": true,
  "processing_id": "uuid-here",
  "status": "completed",
  "progress": 100,
  "message": "Processing completed successfully",
  "thumbnail_url": "http://example.com/storage/sermons/thumbnails/example.jpg",
  "thumbnail_generated": true,
  "thumbnail_generated_at": "2023-12-25T10:00:00.000000Z",
  "additional_data": {
    "sermon_id": 123,
    "video_preserved": true,
    "thumbnail_metadata": {
      "timestamp": 60.0,
      "video_duration": 1800.0,
      "generated_at": "2023-12-25T10:00:00.000000Z"
    }
  }
}
```

**Thumbnail Status Fields:**
- `thumbnail_url`: Full URL to thumbnail image (null if not generated)
- `thumbnail_generated`: Boolean indicating if thumbnail exists
- `thumbnail_generated_at`: ISO timestamp of generation
- `thumbnail_metadata`: Generation details in additional_data

## Thumbnail Serving

### Direct Thumbnail Endpoint

- `GET /christ/sermons/{sermon:slug}/thumbnail` - Serve thumbnail image directly

Features:
- Proper content-type detection (JPEG, PNG, WebP)
- HTTP caching headers (24-hour cache)
- ETag and Last-Modified headers
- 404 responses for missing thumbnails

### Caching Headers

Thumbnails are served with optimal caching headers:
- `Cache-Control: public, max-age=86400` (24 hours)
- `ETag: md5-hash-of-file`
- `Last-Modified: file-modification-time`

## Social Media Integration

### Open Graph Meta Tags

Sermon pages now include comprehensive Open Graph meta tags:

```html
<meta property="og:title" content="Sermon Title - Crockenhill Baptist Church">
<meta property="og:description" content="Sermon by Preacher on Date - Reference">
<meta property="og:type" content="article">
<meta property="og:url" content="current-page-url">
<meta property="og:image" content="thumbnail-url">
<meta property="og:image:width" content="1280">
<meta property="og:image:height" content="720">
<meta property="og:site_name" content="Crockenhill Baptist Church">
```

### Twitter Card Support

Twitter Card meta tags are also included:

```html
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="Sermon Title">
<meta name="twitter:description" content="Sermon description">
<meta name="twitter:image" content="thumbnail-url">
```

### Graceful Fallbacks

- When no thumbnail exists, image meta tags are omitted
- Descriptions handle missing bible references gracefully
- All meta tags work with or without thumbnails

## Error Handling

### Missing Thumbnails

- API responses include `thumbnail_url: null` when no thumbnail exists
- Direct thumbnail requests return 404 for missing thumbnails
- Processing status includes `thumbnail_generated: false` when appropriate

### File System Issues

- Thumbnail serving returns 404 if file doesn't exist on disk
- API responses handle storage disk configuration changes
- No exceptions are thrown for thumbnail-related failures

## Testing

Comprehensive test coverage includes:

### API Tests
- Sermon listing with thumbnail URLs
- Individual sermon retrieval
- Filtering by thumbnail availability
- Null handling for missing thumbnails

### Thumbnail Serving Tests
- Content-type detection for different formats
- Caching header verification
- 404 handling for missing files
- Security validation

### Processing Status Tests
- Thumbnail information in status responses
- Handling of missing sermons
- Null thumbnail scenarios

### Open Graph Tests
- Meta tag generation with thumbnails
- Fallback behavior without thumbnails
- URL and description formatting

## Configuration

### Storage Configuration

Thumbnail serving uses the configured storage disk from `config/thumbnail-generation.php`:

```php
'storage' => [
    'disk' => env('THUMBNAIL_STORAGE_DISK', 'public'),
    'path' => env('THUMBNAIL_STORAGE_PATH', 'sermons/thumbnails'),
],
```

### Caching Configuration

HTTP caching behavior is configurable:

```php
'caching' => [
    'enabled' => env('THUMBNAIL_CACHING_ENABLED', true),
    'max_age' => env('THUMBNAIL_CACHE_MAX_AGE', 86400), // 24 hours
    'etag_enabled' => env('THUMBNAIL_ETAG_ENABLED', true),
    'last_modified_enabled' => env('THUMBNAIL_LAST_MODIFIED', true),
],
```

### Social Media Configuration

Open Graph and Twitter Card settings:

```php
'social_media' => [
    'og_image_width' => env('THUMBNAIL_OG_WIDTH', 1200),
    'og_image_height' => env('THUMBNAIL_OG_HEIGHT', 630),
    'twitter_card_type' => env('THUMBNAIL_TWITTER_CARD', 'summary_large_image'),
    'optimize_for_sharing' => env('THUMBNAIL_OPTIMIZE_SHARING', true),
],
```

## Performance Considerations

- Thumbnails are served with 24-hour cache headers
- ETag support enables conditional requests
- Storage URLs are generated efficiently
- No database queries for thumbnail serving (direct file access)

## Security

- File path validation prevents directory traversal
- Content-type detection based on file extension
- Proper HTTP headers for browser security
- No sensitive information exposed in thumbnail URLs