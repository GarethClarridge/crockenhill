# Shared Scenario Builders

Each class in this directory builds a multi-step, realistic test fixture for a domain area. Builders compose factories into the shapes that real workflows produce — services with positioned items, processing logs with sections, admin users with verified emails — so tests don't have to wire those relationships by hand.

## When to reach for a scenario builder

Reach for one when a test needs more than a single `Model::factory()->create()` call and the multi-model shape it builds will be repeated across the suite. A bespoke fixture inside a test is fine; a bespoke fixture that gets copy-pasted into a third test is the signal to promote it here.

Pure model unit tests — "this column casts to that enum", "this relationship returns a `BelongsTo`" — should keep using factories directly. The scenario layer is for shapes that cross models.

## Available builders

| Builder | What it composes |
|---------|------------------|
| `AdminUserScenario` | Verified admin user creation and `actingAs` helpers |
| `ChurchServiceScenario` | `ChurchService` + positioned `ChurchServiceItem` rows, with source and review-state presets |
| `MediaUploadScenario` | Fake media disks, fake audio/video uploads, Livewire upload wrappers |
| `ProcessingLogScenario` | `MediaProcessingLog` rows for audio, video, and livestream entrypoints |
| `ServiceSectionScenario` | `ServiceSection` rows attached to a processing log, with confidence/classification presets |
| `OpenLpArchiveFactory` | Synthetic OpenLP `.osz` archives for import tests |

## Trait wrappers

`tests/Traits/BuildsTestScenarios.php` exposes the most common entry points as `protected` test methods so tests read `$this->createVerifiedAdmin()` instead of `AdminUserScenario::create()`. Add a trait method when the same scenario call appears in three or more tests.

## Migration policy

Bespoke fixture setups in `tests/Feature/` and `tests/Integration/` should move onto the scenario layer over time, especially when:

- The same multi-model shape is built in more than two tests.
- The setup currently leaks domain assumptions (e.g. "every service has an item at position 1") that the builder can centralise.
- A test reads as more setup than assertion.

Avoid mass-migrating low-value cases. A single factory call in a model test is clearer than a builder call.
