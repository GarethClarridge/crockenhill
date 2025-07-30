# Technology Stack

## Framework & Language

- **Laravel 12**: PHP web framework (requires PHP 8.2+)
- **PHP 8.2+**: Server-side language with strict typing enabled
- **Livewire 3**: Full-stack framework for dynamic interfaces

## Frontend

- **Vite**: Modern build tool and dev server
- **Tailwind CSS 3**: Utility-first CSS framework with custom fonts (Lato, Oswald)
- **SCSS**: CSS preprocessor for additional styling
- **Blade**: Laravel's templating engine
- **Alpine.js**: Lightweight JavaScript framework (via Livewire)

## Key Dependencies

- **Spatie Laravel Data**: DTOs with validation and casting
- **Spatie Google Calendar**: Google Calendar API integration
- **OpenAI PHP Laravel**: AI services integration
- **Intervention Image**: Image processing
- **Laravel Sanctum**: API authentication
- **Blade Heroicons**: Icon components

## Development Tools

- **Laravel Pint**: Code formatting (PSR-12 standard)
- **Larastan (PHPStan)**: Static analysis
- **PHPUnit**: Testing framework
- **Laravel Debugbar**: Development debugging
- **Laravel Sail**: Docker development environment

## Common Commands

### Development
```bash
# Start Sail environment
./vendor/bin/sail up -d

# Stop Sail environment
./vendor/bin/sail down

# Watch for file changes (Vite)
./vendor/bin/sail npm run dev

# Build for production
./vendor/bin/sail npm run build

# Run queue workers
./vendor/bin/sail artisan queue:work
```

### Testing
```bash
# Run all tests
./vendor/bin/sail test

# Run specific test suite
./vendor/bin/sail test --testsuite=Feature
./vendor/bin/sail test --testsuite=Unit

# Run with coverage
./vendor/bin/sail test --coverage
```

### Code Quality
```bash
# Format code
./vendor/bin/sail composer pint

# Static analysis
./vendor/bin/sail composer phpstan

# Clear caches
./vendor/bin/sail artisan optimize:clear
```

### Database
```bash
# Run migrations
./vendor/bin/sail artisan migrate

# Seed database
./vendor/bin/sail artisan db:seed

# Fresh migration with seeding
./vendor/bin/sail artisan migrate:fresh --seed

# Access database CLI
./vendor/bin/sail mysql
```

## Architecture Patterns

- **Service Layer**: Business logic in dedicated service classes
- **Form Requests**: Input validation and authorization
- **Eloquent Models**: Database interactions with proper relationships
- **Enums**: Type-safe constants (PHP 8.1+ enums)
- **Policies**: Authorization logic
- **Jobs & Queues**: Background processing
- **DTOs**: Data transfer objects using Spatie Laravel Data