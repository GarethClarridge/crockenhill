# Project Structure

## Laravel Application Structure

### Core Application (`app/`)

- **Console/Commands/**: Artisan commands (e.g., Google Calendar sync)
- **Enums/**: Type-safe enums (MeetingFrequency, MeetingType, PageArea, SermonService)
- **Http/Controllers/**: Request handling with dedicated controllers for each domain
- **Http/Requests/**: Form request validation classes
- **Http/Resources/**: API resource transformations
- **Livewire/**: Full-stack components for dynamic interfaces
- **Models/**: Eloquent models (Sermon, Meeting, Page, User, CalendarEvent)
- **Policies/**: Authorization logic for models
- **Providers/**: Service providers for application bootstrapping
- **Services/**: Business logic layer (CalendarService, PageImageService)

### Domain Organization

The application follows domain-driven organization:

- **Sermons**: Core domain with models, controllers, policies, and services
- **Meetings**: Church meeting management with recurring event support
- **Pages**: CMS functionality for church content
- **Calendar**: Google Calendar integration
- **Auth**: User authentication and member areas

### Configuration (`config/`)

- **sermon-processing.php**: AI processing configuration
- **calendar.php**: Google Calendar settings
- **openai.php**: AI service configuration
- Standard Laravel config files

### Database (`database/`)

- **migrations/**: Database schema with proper indexing
- **factories/**: Model factories for testing
- **seeders/**: Database seeding for development

### Frontend (`resources/`)

- **css/**: SCSS files with custom variables and CBC-specific styling
- **js/**: JavaScript modules and page-specific scripts
- **views/**: Blade templates organized by domain
  - **admin/**: Administrative interfaces
  - **sermons/**: Sermon display and management
  - **meetings/**: Meeting pages
  - **components/**: Reusable Blade components

### Storage Structure

- **app/google-calendar/**: Google Calendar credentials
- **app/services/**: Service-related files
- **app/transcripts/**: AI-generated sermon transcripts (planned)
- **public/media/sermons/**: Sermon audio files
- **public/media/documents/**: Document uploads

### Testing (`tests/`)

- **Feature/**: Integration tests for complete workflows
- **Unit/**: Isolated unit tests for individual components
- **Browser/**: Browser testing setup

## File Naming Conventions

- **Controllers**: Singular noun + "Controller" (SermonController)
- **Models**: Singular noun (Sermon, Meeting)
- **Migrations**: Descriptive action with timestamp
- **Views**: Lowercase with hyphens, organized by controller
- **Services**: Descriptive name + "Service" (CalendarService)
- **Enums**: Descriptive name without suffix (SermonService)

## Code Organization Patterns

- **Single Responsibility**: Each class has one clear purpose
- **Service Layer**: Business logic separated from controllers
- **Repository Pattern**: Not used - direct Eloquent usage preferred
- **Form Requests**: Input validation separated from controllers
- **Resource Classes**: API response formatting
- **Policy Classes**: Authorization logic centralized

## Key Directories to Know

- **app/Models/**: Start here for understanding data structure
- **app/Http/Controllers/**: Main application logic entry points
- **app/Services/**: Business logic implementation
- **resources/views/**: Frontend templates
- **config/**: Application configuration
- **database/migrations/**: Database schema evolution