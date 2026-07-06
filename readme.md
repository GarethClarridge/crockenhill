# Crockenhill Baptist Church Website

A modern, Laravel-based church management system and website for Crockenhill Baptist Church. This project handles static content, sermon management with AI-powered features, meeting scheduling, and automated media processing.

## Features

- **Sermon Management**:
    - Audio and video sermon uploads.
    - Automated transcription using OpenAI Whisper.
    - AI-powered sermon analysis (series detection, Bible reference extraction, key points).
    - Branded thumbnail generation from video frames.
    - Automated podcast feed generation.
- **Media Processing**:
    - Automated livestream segmentation and sermon extraction.
    - Hybrid local/cloud processing with S3-compatible storage (DigitalOcean Spaces).
    - RMS analysis for video segmentation.
- **Meeting & Events**:
    - Full meeting management with recurring support.
    - Google Calendar integration.
- **Content Management**:
    - Page management organized by areas (Christ, Church, Community, Members).
    - Support for static and dynamic content.
- **Members Area**:
    - Authenticated area for church members.
- **Sitemap & SEO**:
    - Automated sitemap generation.
    - SEO-friendly URL structures and metadata.

## Tech Stack

- **Backend**: Laravel 12, PHP 8.4
- **Frontend**: Livewire 3, Tailwind CSS 3, Alpine.js 3 (TALL stack)
- **Media Processing**: FFmpeg, Intervention Image
- **AI**: OpenAI PHP Laravel (Whisper, GPT)
- **Storage**: DigitalOcean Spaces / S3
- **Development**: Laravel Sail (Docker)

## Requirements

- Docker & Docker Compose
- PHP 8.4 (for local development without Docker)
- Composer

## Getting Started (Development)

1. **Clone the repository**:
   ```bash
   git clone https://github.com/crockenhill/crockenhill.git
   cd crockenhill
   ```

2. **Install dependencies**:
   ```bash
   docker run --rm \
       -u "$(id -u):$(id -g)" \
       -v "$(pwd):/var/www/html" \
       -w /var/www/html \
       laravelsail/php84-composer:latest \
       composer install --ignore-platform-reqs
   ```

3. **Setup environment**:
   ```bash
   cp .env.example .env
   ```

4. **Start the development environment**:
   ```bash
   ./vendor/bin/sail up -d
   ```

5. **Generate app key**:
   ```bash
   ./vendor/bin/sail artisan key:generate
   ```

6. **Run migrations and seeders**:
   ```bash
   ./vendor/bin/sail artisan migrate --seed
   ```

7. **Compile assets**:
   ```bash
   ./vendor/bin/sail npm run dev
   ```

## Testing

The project has a comprehensive test suite with over 1,200 tests.

```bash
# Run all tests in parallel
./vendor/bin/sail artisan test --parallel --compact

# Run a specific test file
./vendor/bin/sail artisan test --compact tests/Feature/SermonTest.php
```

## Documentation

Start at [docs/README.md](docs/README.md) — it maps the whole `docs/` directory. Highlights:

- [Production operations](docs/operations/production.md) — stack, queues, deploy/rollback
- [API reference](docs/api/media-processing.md)
- [Design style guide](docs/design-style-guide.md)
- [SEO setup guide](docs/operations/SEO_SETUP_GUIDE.md)

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
