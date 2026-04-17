# Codex Project Instructions

This repository is a Laravel 12 church website (TALL stack: Tailwind + Alpine + Livewire + Laravel) with FFmpeg-based media processing.

## Skills

A skill is a set of local instructions in a `SKILL.md` file.

### Available skills
- `livewire-development`: Use for Livewire component creation/updates, `wire:*` directives, reactivity bugs, or Livewire tests.  
  File: `/Users/garethclarridge/Projects/crockenhill/.claude/skills/livewire-development/SKILL.md`
- `tailwindcss-development`: Use for styling/layout/Tailwind utility changes, responsive work, or dark mode updates.  
  File: `/Users/garethclarridge/Projects/crockenhill/.claude/skills/tailwindcss-development/SKILL.md`
- `frontend-design`: Use for project-specific UI design decisions on public/admin pages, design token usage, component reuse, and avoiding legacy visual patterns.  
  File: `/Users/garethclarridge/Projects/crockenhill/.claude/skills/frontend-design/SKILL.md`

### Skill usage rules
- If a task clearly matches a skill, open its `SKILL.md` and follow it.
- Use the minimal set of skills needed for the task.
- If a skill cannot be read, continue with best-effort fallback and state that briefly.

## Required workflow

- Run project commands through Sail: `vendor/bin/sail ...`
- Use Laravel conventions first (`artisan make:*`, Form Requests, Eloquent relationships, queued jobs for long-running work).
- Keep to existing structure and conventions; do not introduce new base folders without approval.
- Do not change dependencies without approval.
- Do not create documentation files unless explicitly requested.

## Boost/MCP preference

- If Laravel Boost MCP tools are available, use them first for Laravel/package docs and app inspection.
- Prefer docs-first lookups (`search-docs`) before implementing framework/package-level changes.
- Prefer MCP helpers for route/artisan/config/database introspection over ad-hoc scripts when possible.

## Quality gates before finishing code changes

- Run focused tests for changed behavior (minimum necessary scope).
- Run static analysis: `vendor/bin/sail composer phpstan` (must remain at 0 errors).
- Run formatting: `vendor/bin/sail bin pint --dirty`.

## Testing rules

- Every code change must be programmatically tested (new or updated tests).
- Prefer feature tests for HTTP/integration behavior; use unit tests for isolated logic.
- Use compact output and filters when possible, for example:  
  `vendor/bin/sail artisan test --compact tests/Feature/ExampleTest.php`  
  `vendor/bin/sail artisan test --compact --filter=testName`
- For broad runs, parallel mode is preferred:  
  `vendor/bin/sail artisan test --parallel --compact`

## Laravel/PHP conventions

- Always use explicit return types and parameter type hints.
- Always use curly braces for control structures.
- Use constructor property promotion where appropriate.
- Use `config(...)` instead of `env(...)` outside config files.
- Prefer model queries/relationships over raw `DB::` usage.
- Avoid N+1 queries via eager loading.
- In Laravel 12, middleware/bootstrapping belongs in `bootstrap/app.php` (not `app/Http/Kernel.php`).

## Frontend conventions

- Follow existing Tailwind v3 patterns in this repo.
- Use `wire:model.live` when real-time Livewire updates are intended.
- Livewire components should keep server-side state and validate/authorize actions.

## Design workflow (Codex)

- For any UI/styling/layout task, read `docs/design-style-guide.md` before making changes.
- Use `frontend-design` for all UI work; combine with `tailwindcss-development` and/or `livewire-development` only when needed.
- Reuse existing shared Blade components first, and check live variants at `/dev/components` (`resources/views/dev/components.blade.php`).
- Use project brand tokens and typography intentionally (`cbc-*` colors, `font-display` for major headings, `font-sans` for body text).
- Do not introduce legacy patterns called out in the style guide (for example, indigo focus-ring chains and one-off raw form/button markup where shared components exist).
- Before finishing UI changes, validate mobile layout, internal `wire:navigate` links, loading/empty/error/success states, and keyboard/focus behavior.

## Jules / Remote CI environment

When running outside Laravel Sail (e.g. in Jules, Codex, or other remote VM agents):

### CRITICAL: First step for EVERY task
Services installed via apt are not running after snapshot restore. You MUST run these commands at the very start of every task, before any artisan/test/migration commands:
```bash
sudo service mysql start
sudo service redis-server start
```
Then verify they are running:
```bash
sudo mysqladmin ping -ppassword 2>/dev/null && echo "MySQL OK" || echo "MySQL FAILED"
redis-cli ping 2>/dev/null && echo "Redis OK" || echo "Redis FAILED"
```
If either service fails to start, check logs with `sudo cat /var/log/mysql/error.log` or `sudo cat /var/log/redis/redis-server.log`.

### Environment setup
- The setup script `jules-setup.sh` installs PHP 8.4, Composer, MySQL, and Redis natively via apt (no Docker pulls — avoids Docker Hub rate limits).
- `.env.jules` is copied to `.env` during setup — it points DB/Redis to `127.0.0.1` instead of Sail's Docker service names.
- `APP_KEY` is generated automatically by the setup script.

### Running commands WITHOUT Sail
Do **not** prefix commands with `vendor/bin/sail` — run them directly:
- `php artisan test --parallel --compact` — run all tests
- `php artisan test --compact tests/Feature/ExampleTest.php` — run a single file
- `php artisan test --compact --filter=testName` — run a single test
- `composer phpstan` — static analysis (must stay at 0 errors)
- `./vendor/bin/pint --dirty` — format changed files
- `php artisan migrate --force --no-interaction` — run migrations

### What's different from local
- MySQL and Redis are installed natively (not via Docker/Sail containers).
- No Selenium/Dusk — browser tests are skipped.
- No Mailpit — mail driver is set to `log`.
- Queue is `sync` — jobs execute immediately.
- Transcription and analysis use `mock` services.

### Testing databases
The setup script creates:
- `crockenhill` — main database
- `testing` — base testing database
- `crockenhill_test_*` — wildcard grant for parallel test worker databases

## Project context quick map

- Core domains: pages, sermons, meetings, livestream segmentation/extraction.
- Key services live in `app/Services/` (processing, storage, transcription, thumbnails, image handling).
- API processing endpoints: `/api/sermons/{audio|video|livestream}` and `/api/sermons/processing/{id}/status`.

===

<laravel-boost-guidelines>
=== foundation rules ===

# Laravel Boost Guidelines

The Laravel Boost guidelines are specifically curated by Laravel maintainers for this application. These guidelines should be followed closely to ensure the best experience when building Laravel applications.

## Foundational Context

This application is a Laravel application and its main Laravel ecosystems package & versions are below. You are an expert with them all. Ensure you abide by these specific packages & versions.

- php - 8.4.18
- laravel/framework (LARAVEL) - v12
- laravel/prompts (PROMPTS) - v0
- laravel/sanctum (SANCTUM) - v4
- livewire/livewire (LIVEWIRE) - v3
- larastan/larastan (LARASTAN) - v3
- laravel/dusk (DUSK) - v8
- laravel/mcp (MCP) - v0
- laravel/pint (PINT) - v1
- laravel/sail (SAIL) - v1
- phpunit/phpunit (PHPUNIT) - v11
- alpinejs (ALPINEJS) - v3
- tailwindcss (TAILWINDCSS) - v3

## Skills Activation

This project has domain-specific skills available. You MUST activate the relevant skill whenever you work in that domain—don't wait until you're stuck.

- `livewire-development` — Develops reactive Livewire 3 components. Activates when creating, updating, or modifying Livewire components; working with wire:model, wire:click, wire:loading, or any wire: directives; adding real-time updates, loading states, or reactivity; debugging component behavior; writing Livewire tests; or when the user mentions Livewire, component, counter, or reactive UI.
- `tailwindcss-development` — Styles applications using Tailwind CSS v3 utilities. Activates when adding styles, restyling components, working with gradients, spacing, layout, flex, grid, responsive design, dark mode, colors, typography, or borders; or when the user mentions CSS, styling, classes, Tailwind, restyle, hero section, cards, buttons, or any visual/UI changes.
- `spatie-javascript` — Apply Spatie&#039;s JavaScript coding standards for any task that creates, edits, reviews, refactors, or formats JavaScript or TypeScript code; use for variable declarations, comparisons, functions, destructuring, and Prettier configuration to align with Spatie&#039;s JS conventions.
- `spatie-laravel-php` — Apply Spatie&#039;s Laravel and PHP coding standards for any task that creates, edits, reviews, refactors, or formats Laravel/PHP code or Blade templates; use for controllers, Eloquent models, routes, config, validation, migrations, tests, and related files to align with Laravel conventions and PSR-12.
- `spatie-security` — Apply Spatie&#039;s security guidelines when configuring applications, databases, or servers, or when reviewing code for security concerns; use for SSL setup, CSRF protection, password hashing, database permissions, and server hardening.
- `spatie-version-control` — Apply Spatie&#039;s version control conventions when creating commits, branches, pull requests, or managing Git repositories; use for naming repos, writing commit messages, choosing branch strategies, and merging code.

## Conventions

- You must follow all existing code conventions used in this application. When creating or editing a file, check sibling files for the correct structure, approach, and naming.
- Use descriptive names for variables and methods. For example, `isRegisteredForDiscounts`, not `discount()`.
- Check for existing components to reuse before writing a new one.

## Verification Scripts

- Do not create verification scripts or tinker when tests cover that functionality and prove they work. Unit and feature tests are more important.

## Application Structure & Architecture

- Stick to existing directory structure; don't create new base folders without approval.
- Do not change the application's dependencies without approval.

## Frontend Bundling

- If the user doesn't see a frontend change reflected in the UI, it could mean they need to run `vendor/bin/sail npm run build`, `vendor/bin/sail npm run dev`, or `vendor/bin/sail composer run dev`. Ask them.

## Documentation Files

- You must only create documentation files if explicitly requested by the user.

## Replies

- Be concise in your explanations - focus on what's important rather than explaining obvious details.

=== boost rules ===

# Laravel Boost

- Laravel Boost is an MCP server that comes with powerful tools designed specifically for this application. Use them.

## Artisan

- Use the `list-artisan-commands` tool when you need to call an Artisan command to double-check the available parameters.

## URLs

- Whenever you share a project URL with the user, you should use the `get-absolute-url` tool to ensure you're using the correct scheme, domain/IP, and port.

## Tinker / Debugging

- You should use the `tinker` tool when you need to execute PHP to debug code or query Eloquent models directly.
- Use the `database-query` tool when you only need to read from the database.

## Reading Browser Logs With the `browser-logs` Tool

- You can read browser logs, errors, and exceptions using the `browser-logs` tool from Boost.
- Only recent browser logs will be useful - ignore old logs.

## Searching Documentation (Critically Important)

- Boost comes with a powerful `search-docs` tool you should use before trying other approaches when working with Laravel or Laravel ecosystem packages. This tool automatically passes a list of installed packages and their versions to the remote Boost API, so it returns only version-specific documentation for the user's circumstance. You should pass an array of packages to filter on if you know you need docs for particular packages.
- Search the documentation before making code changes to ensure we are taking the correct approach.
- Use multiple, broad, simple, topic-based queries at once. For example: `['rate limiting', 'routing rate limiting', 'routing']`. The most relevant results will be returned first.
- Do not add package names to queries; package information is already shared. For example, use `test resource table`, not `filament 4 test resource table`.

### Available Search Syntax

1. Simple Word Searches with auto-stemming - query=authentication - finds 'authenticate' and 'auth'.
2. Multiple Words (AND Logic) - query=rate limit - finds knowledge containing both "rate" AND "limit".
3. Quoted Phrases (Exact Position) - query="infinite scroll" - words must be adjacent and in that order.
4. Mixed Queries - query=middleware "rate limit" - "middleware" AND exact phrase "rate limit".
5. Multiple Queries - queries=["authentication", "middleware"] - ANY of these terms.

=== php rules ===

# PHP

- Always use strict typing at the head of a `.php` file: `declare(strict_types=1);`.
- Always use curly braces for control structures, even for single-line bodies.

## Constructors

- Use PHP 8 constructor property promotion in `__construct()`.
    - <code-snippet>public function __construct(public GitHub $github) { }</code-snippet>
- Do not allow empty `__construct()` methods with zero parameters unless the constructor is private.

## Type Declarations

- Always use explicit return type declarations for methods and functions.
- Use appropriate PHP type hints for method parameters.

<code-snippet name="Explicit Return Types and Method Params" lang="php">
protected function isAccessible(User $user, ?string $path = null): bool
{
    ...
}
</code-snippet>

## Enums

- Typically, keys in an Enum should be TitleCase. For example: `FavoritePerson`, `BestLake`, `Monthly`.

## Comments

- Prefer PHPDoc blocks over inline comments. Never use comments within the code itself unless the logic is exceptionally complex.

## PHPDoc Blocks

- Add useful array shape type definitions when appropriate.

=== sail rules ===

# Laravel Sail

- This project runs inside Laravel Sail's Docker containers. You MUST execute all commands through Sail.
- Start services using `vendor/bin/sail up -d` and stop them with `vendor/bin/sail stop`.
- Open the application in the browser by running `vendor/bin/sail open`.
- Always prefix PHP, Artisan, Composer, and Node commands with `vendor/bin/sail`. Examples:
    - Run Artisan Commands: `vendor/bin/sail artisan migrate`
    - Install Composer packages: `vendor/bin/sail composer install`
    - Execute Node commands: `vendor/bin/sail npm run dev`
    - Execute PHP scripts: `vendor/bin/sail php [script]`
- View all available Sail commands by running `vendor/bin/sail` without arguments.

=== tests rules ===

# Test Enforcement

- Every change must be programmatically tested. Write a new test or update an existing test, then run the affected tests to make sure they pass.
- Run the minimum number of tests needed to ensure code quality and speed. Use `vendor/bin/sail artisan test --compact` with a specific filename or filter.

=== laravel/core rules ===

# Do Things the Laravel Way

- Use `vendor/bin/sail artisan make:` commands to create new files (i.e. migrations, controllers, models, etc.). You can list available Artisan commands using the `list-artisan-commands` tool.
- If you're creating a generic PHP class, use `vendor/bin/sail artisan make:class`.
- Pass `--no-interaction` to all Artisan commands to ensure they work without user input. You should also pass the correct `--options` to ensure correct behavior.

## Database

- Always use proper Eloquent relationship methods with return type hints. Prefer relationship methods over raw queries or manual joins.
- Use Eloquent models and relationships before suggesting raw database queries.
- Avoid `DB::`; prefer `Model::query()`. Generate code that leverages Laravel's ORM capabilities rather than bypassing them.
- Generate code that prevents N+1 query problems by using eager loading.
- Use Laravel's query builder for very complex database operations.

### Model Creation

- When creating new models, create useful factories and seeders for them too. Ask the user if they need any other things, using `list-artisan-commands` to check the available options to `vendor/bin/sail artisan make:model`.

### APIs & Eloquent Resources

- For APIs, default to using Eloquent API Resources and API versioning unless existing API routes do not, then you should follow existing application convention.

## Controllers & Validation

- Always create Form Request classes for validation rather than inline validation in controllers. Include both validation rules and custom error messages.
- Check sibling Form Requests to see if the application uses array or string based validation rules.

## Authentication & Authorization

- Use Laravel's built-in authentication and authorization features (gates, policies, Sanctum, etc.).

## URL Generation

- When generating links to other pages, prefer named routes and the `route()` function.

## Queues

- Use queued jobs for time-consuming operations with the `ShouldQueue` interface.

## Configuration

- Use environment variables only in configuration files - never use the `env()` function directly outside of config files. Always use `config('app.name')`, not `env('APP_NAME')`.

## Testing

- When creating models for tests, use the factories for the models. Check if the factory has custom states that can be used before manually setting up the model.
- Faker: Use methods such as `$this->faker->word()` or `fake()->randomDigit()`. Follow existing conventions whether to use `$this->faker` or `fake()`.
- When creating tests, make use of `vendor/bin/sail artisan make:test [options] {name}` to create a feature test, and pass `--unit` to create a unit test. Most tests should be feature tests.

## Vite Error

- If you receive an "Illuminate\Foundation\ViteException: Unable to locate file in Vite manifest" error, you can run `vendor/bin/sail npm run build` or ask the user to run `vendor/bin/sail npm run dev` or `vendor/bin/sail composer run dev`.

=== laravel/v12 rules ===

# Laravel 12

- CRITICAL: ALWAYS use `search-docs` tool for version-specific Laravel documentation and updated code examples.
- Since Laravel 11, Laravel has a new streamlined file structure which this project uses.

## Laravel 12 Structure

- In Laravel 12, middleware are no longer registered in `app/Http/Kernel.php`.
- Middleware are configured declaratively in `bootstrap/app.php` using `Application::configure()->withMiddleware()`.
- `bootstrap/app.php` is the file to register middleware, exceptions, and routing files.
- `bootstrap/providers.php` contains application specific service providers.
- The `app\Console\Kernel.php` file no longer exists; use `bootstrap/app.php` or `routes/console.php` for console configuration.
- Console commands in `app/Console/Commands/` are automatically available and do not require manual registration.

## Database

- When modifying a column, the migration must include all of the attributes that were previously defined on the column. Otherwise, they will be dropped and lost.
- Laravel 12 allows limiting eagerly loaded records natively, without external packages: `$query->latest()->limit(10);`.

### Models

- Casts can and likely should be set in a `casts()` method on a model rather than the `$casts` property. Follow existing conventions from other models.

=== livewire/core rules ===

# Livewire

- Livewire allows you to build dynamic, reactive interfaces using only PHP — no JavaScript required.
- Instead of writing frontend code in JavaScript frameworks, you use Alpine.js to build the UI when client-side interactions are required.
- State lives on the server; the UI reflects it. Validate and authorize in actions (they're like HTTP requests).
- IMPORTANT: Activate `livewire-development` every time you're working with Livewire-related tasks.

=== pint/core rules ===

# Laravel Pint Code Formatter

- You must run `vendor/bin/sail bin pint --dirty` before finalizing changes to ensure your code matches the project's expected style.
- Do not run `vendor/bin/sail bin pint --test`, simply run `vendor/bin/sail bin pint` to fix any formatting issues.

=== phpunit/core rules ===

# PHPUnit

- This application uses PHPUnit for testing. All tests must be written as PHPUnit classes. Use `vendor/bin/sail artisan make:test --phpunit {name}` to create a new test.
- If you see a test using "Pest", convert it to PHPUnit.
- Every time a test has been updated, run that singular test.
- When the tests relating to your feature are passing, ask the user if they would like to also run the entire test suite to make sure everything is still passing.
- Tests should cover all happy paths, failure paths, and edge cases.
- You must not remove any tests or test files from the tests directory without approval. These are not temporary or helper files; these are core to the application.

## Running Tests

- Run the minimal number of tests, using an appropriate filter, before finalizing.
- To run all tests: `vendor/bin/sail artisan test --compact`.
- To run all tests in a file: `vendor/bin/sail artisan test --compact tests/Feature/ExampleTest.php`.
- To filter on a particular test name: `vendor/bin/sail artisan test --compact --filter=testName` (recommended after making a change to a related file).

=== tailwindcss/core rules ===

# Tailwind CSS

- Always use existing Tailwind conventions; check project patterns before adding new ones.
- IMPORTANT: Always use `search-docs` tool for version-specific Tailwind CSS documentation and updated code examples. Never rely on training data.
- IMPORTANT: Activate `tailwindcss-development` every time you're working with a Tailwind CSS or styling-related task.

=== spatie/guidelines-skills rules ===

# Project Coding Guidelines

- This codebase follows Spatie's coding guidelines.
- Always activate the `spatie-laravel-php` skill when writing, editing, reviewing, or formatting Laravel or PHP code.
- Always activate the `spatie-javascript` skill when writing, editing, reviewing, or formatting JavaScript or TypeScript code.
- Always activate the `spatie-version-control` skill when creating commits, branches, or managing Git operations.
- Always activate the `spatie-security` skill when configuring security, reviewing authentication, or setting up servers and databases.
</laravel-boost-guidelines>
